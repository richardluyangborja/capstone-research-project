"""The Groq provider seam for management reports.

The service receives Laravel's aggregated context, asks Groq for one JSON object, validates the
shape and the fixed ID vocabulary, and returns prose only. It never writes a route: Laravel owns
the route map. There is no retry loop — Laravel's timeout and rules fallback are the resilience.
"""

from __future__ import annotations

import json
import logging
import re
import time

import httpx
from pydantic import ValidationError

from .config import Settings
from .schemas import GeneratedReport, GeneratedReportResponse, ReportContext, Usage

logger = logging.getLogger("uvicorn.error")

SYSTEM_PROMPT = """You are the reporting analyst for a Philippine staffing CRM.
Use only the aggregated numbers in the supplied JSON. Do not invent records, names, dates,
amounts, URLs, email addresses or phone numbers. Write in a concise, factual management voice.
Amounts are Philippine pesos; format them compactly (for example PHP 1,000,000).
Return one JSON object only, with no markdown and no commentary."""

VARIANT_GUIDANCE = {
    "executive": (
        "Question: what is the state of the book this period? "
        "Cover the work that needs attention, the pipeline and follow-ups."
    ),
    "pipeline": (
        "Question: where is the revenue? "
        "Cover the open stages, stalled deals and the win rate."
    ),
    "client_health": (
        "Question: which clients need care? "
        "Cover attention, CSAT, NPS and churn."
    ),
}

ALLOWED_INSIGHTS = {
    "executive": ("attention", "pipeline", "follow_ups", "win_rate"),
    "pipeline": ("stages", "stalled", "win_rate", "follow_ups"),
    "client_health": ("attention", "csat", "nps", "churn"),
}

ALLOWED_ACTIONS = {
    "executive": ("read_answers", "open_board", "open_worklist", "open_clients"),
    "pipeline": ("open_pipeline", "open_board", "open_deals", "open_worklist"),
    "client_health": ("read_answers", "open_satisfaction", "open_clients", "open_followups"),
}

UNSAFE_TEXT = re.compile(r"https?://|www\.[^\s]+|[\w.+-]+@[\w-]+\.[\w.-]+", re.IGNORECASE)

SCHEMA_INSTRUCTION = """Return exactly this JSON shape:
{
  "headline": "one sentence, at most 90 characters",
  "narrative": "two or three short sentences, at most 700 characters",
  "insights": [
    {"id": "allowed_id", "title": "at most 60 characters", "body": "at most 220 characters"}
  ],
  "actions": [
    {"id": "allowed_id", "label": "at most 50 characters", "description": "at most 140 characters"}
  ]
}
The insights array must contain exactly 3 unique items. The actions array must contain exactly 4
unique items. Use only the IDs listed above; do not add fields."""


class ReportGenerationError(Exception):
    """A provider outcome Laravel turns into a rules fallback (or a 429 for a quota rejection)."""

    def __init__(self, reason: str, status_code: int, message: str) -> None:
        super().__init__(message)
        self.reason = reason
        self.status_code = status_code
        self.message = message


def build_messages(context: ReportContext) -> list[dict[str, str]]:
    """Build the two messages sent to Groq. The user message is the context JSON only."""

    variant = context.variant
    system = (
        f"{SYSTEM_PROMPT}\n\n{VARIANT_GUIDANCE[variant]}\n\n"
        f"Allowed insight IDs: {', '.join(ALLOWED_INSIGHTS[variant])}\n"
        f"Allowed action IDs: {', '.join(ALLOWED_ACTIONS[variant])}\n\n"
        f"{SCHEMA_INSTRUCTION}"
    )
    payload = context.model_dump(mode="json")

    return [
        {"role": "system", "content": system},
        {
            "role": "user",
            "content": json.dumps(
                payload,
                ensure_ascii=False,
                separators=(",", ":"),
            ),
        },
    ]


async def generate_report(
    context: ReportContext,
    settings: Settings,
) -> GeneratedReportResponse:
    """Ask Groq for one report. Raises `ReportGenerationError` for every unusable outcome."""

    if not settings.groq_api_key.strip():
        raise ReportGenerationError(
            "not_configured",
            503,
            "The AI service has no Groq API key.",
        )

    messages = build_messages(context)
    started = time.perf_counter()

    try:
        async with httpx.AsyncClient(timeout=settings.ai_timeout_seconds) as client:
            response = await client.post(
                f"{settings.groq_base_url.rstrip('/')}/chat/completions",
                headers={
                    "Authorization": f"Bearer {settings.groq_api_key}",
                    "Content-Type": "application/json",
                },
                json={
                    "model": settings.ai_model,
                    "messages": messages,
                    "temperature": 0.2,
                    "max_tokens": 1200,
                    "reasoning_effort": "low",
                    "stream": False,
                    "response_format": {"type": "json_object"},
                },
            )
    except httpx.TimeoutException as exception:
        raise ReportGenerationError(
            "timeout",
            504,
            "The AI provider did not answer in time.",
        ) from exception
    except httpx.HTTPError as exception:
        raise ReportGenerationError(
            "provider_error",
            502,
            "The AI provider could not be reached.",
        ) from exception

    status = response.status_code

    if status == 429:
        raise ReportGenerationError(
            "rate_limited",
            429,
            "The AI provider rate limit has been reached.",
        )

    if status >= 400:
        raise ReportGenerationError(
            "provider_error",
            502,
            f"The AI provider returned HTTP {status}.",
        )

    try:
        data = response.json()
        content = data["choices"][0]["message"]["content"]
    except (ValueError, KeyError, IndexError, TypeError) as exception:
        raise ReportGenerationError(
            "invalid_response",
            502,
            "The AI provider returned an unreadable response.",
        ) from exception

    try:
        payload = json.loads(content) if isinstance(content, str) else content
    except json.JSONDecodeError as exception:
        raise ReportGenerationError(
            "invalid_response",
            502,
            "The AI provider returned invalid JSON.",
        ) from exception

    try:
        report = GeneratedReport.model_validate(payload)
    except ValidationError as exception:
        raise ReportGenerationError(
            "invalid_response",
            502,
            "The AI provider returned a report outside the contract.",
        ) from exception

    validate_vocabulary(report, context.variant)

    usage = _usage(data)
    elapsed_ms = round((time.perf_counter() - started) * 1000)

    logger.info(
        "management_report.generated variant=%s model=%s status=%s elapsed_ms=%s "
        "prompt_tokens=%s completion_tokens=%s",
        context.variant,
        settings.ai_model,
        status,
        elapsed_ms,
        usage.prompt_tokens if usage else None,
        usage.completion_tokens if usage else None,
    )

    return GeneratedReportResponse(
        **report.model_dump(),
        model=settings.ai_model,
        usage=usage,
    )


def validate_vocabulary(report: GeneratedReport, variant: str) -> None:
    """Reject an invented or duplicate ID, or prose that leaks a URL/email."""

    insight_ids = [insight.id for insight in report.insights]
    action_ids = [action.id for action in report.actions]

    if len(set(insight_ids)) != 3 or any(
        insight_id not in ALLOWED_INSIGHTS[variant] for insight_id in insight_ids
    ):
        raise ReportGenerationError(
            "invalid_response",
            502,
            "The AI provider used an unknown insight ID.",
        )

    if len(set(action_ids)) != 4 or any(
        action_id not in ALLOWED_ACTIONS[variant] for action_id in action_ids
    ):
        raise ReportGenerationError(
            "invalid_response",
            502,
            "The AI provider used an unknown action ID.",
        )

    text = " ".join(
        [
            report.headline,
            report.narrative,
            *[f"{insight.title} {insight.body}" for insight in report.insights],
            *[f"{action.label} {action.description}" for action in report.actions],
        ]
    )

    if UNSAFE_TEXT.search(text):
        raise ReportGenerationError(
            "invalid_response",
            502,
            "The AI provider included a URL or email address.",
        )


def _usage(data: dict) -> Usage | None:
    usage = data.get("usage") if isinstance(data, dict) else None

    if not isinstance(usage, dict):
        return None

    return Usage(
        prompt_tokens=usage.get("prompt_tokens"),
        completion_tokens=usage.get("completion_tokens"),
        total_tokens=usage.get("total_tokens"),
    )
