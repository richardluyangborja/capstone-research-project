"""Tests for the Groq report seam.

No network and no key: `respx` fakes Groq. The tests assert the outgoing payload is the aggregated
context only, and that every provider failure becomes the typed status Laravel expects.
"""

from __future__ import annotations

import json

import httpx
import pytest
import respx
from fastapi.testclient import TestClient

from app.config import Settings, get_settings
from app.main import app

GROQ_URL = "https://api.groq.com/openai/v1/chat/completions"


def make_settings(**overrides) -> Settings:
    values = {
        "groq_api_key": "test-key",
        "ai_model": "openai/gpt-oss-20b",
        "ai_timeout_seconds": 1.0,
        "groq_base_url": "https://api.groq.com/openai/v1",
    }
    values.update(overrides)
    return Settings(**values)


@pytest.fixture
def client() -> TestClient:
    app.dependency_overrides[get_settings] = lambda: make_settings()

    with TestClient(app) as test_client:
        yield test_client

    app.dependency_overrides.clear()


def context(variant: str = "executive") -> dict:
    return {
        "variant": variant,
        "period": {"range": "30d", "start": "2026-09-25", "end": "2026-10-24"},
        "scope": {"tier": "personal", "label": "Your book"},
        "kpis": [
            {
                "id": "new-leads",
                "value": 10,
                "unit": "count",
                "trend": {"direction": "up", "value": 2, "unit": "count"},
            },
            {"id": "pipeline", "value": 1_000_000, "unit": "currency", "trend": None},
        ],
        "line": {"labels": ["Sep 25"], "series": [{"name": "Leads", "values": [10]}]},
        "pipeline": {
            "open_value": 1_000_000,
            "stages": [{"label": "Negotiation", "value": 1_000_000}],
            "closed": {"won": 1, "lost": 0},
            "win_rate": 50,
            "stalled": 0,
        },
        "clients": {"active": 1, "at_risk": 0, "dormant": 0, "churned": 0},
        "satisfaction": {
            "csat": 4.0,
            "nps": 8,
            "responses": 1,
            "attention": 0,
            "bands": {"promoter": 1, "passive": 0, "detractor": 0},
        },
        "follow_ups": {
            "overdue": 1,
            "due_today": 0,
            "by_kind": {"task": 1, "message": 0},
            "open": 1,
        },
        "worklist": {"total": 1, "by_severity": {"danger": 0, "warning": 1, "info": 0}},
    }


def provider_report(variant: str = "executive") -> dict:
    if variant == "pipeline":
        insights = [
            ("stages", "Negotiation holds the value"),
            ("stalled", "No deal is stalled"),
            ("win_rate", "Half of closed deals were won"),
        ]
        actions = ["open_pipeline", "open_board", "open_deals", "open_worklist"]
    elif variant == "client_health":
        insights = [
            ("attention", "No callback is waiting"),
            ("csat", "CSAT is on the high side"),
            ("nps", "Promoters outnumber detractors"),
        ]
        actions = ["read_answers", "open_satisfaction", "open_clients", "open_followups"]
    else:
        insights = [
            ("attention", "One account needs a callback"),
            ("pipeline", "Negotiation holds the value"),
            ("follow_ups", "One follow-up is owed"),
        ]
        actions = ["read_answers", "open_board", "open_worklist", "open_clients"]

    return {
        "headline": "The book is at a 50% win rate with one callback due.",
        "narrative": "The team carries PHP 1,000,000 open and closed one deal this period.",
        "insights": [
            {"id": insight_id, "title": title, "body": "A concise supporting sentence."}
            for insight_id, title in insights
        ],
        "actions": [
            {
                "id": action_id,
                "label": f"Action {index + 1}",
                "description": "A concise next step.",
            }
            for index, action_id in enumerate(actions)
        ],
    }


def groq_response(report: dict) -> dict:
    return {
        "id": "chatcmpl-test",
        "model": "openai/gpt-oss-20b",
        "choices": [{"message": {"content": json.dumps(report)}}],
        "usage": {"prompt_tokens": 420, "completion_tokens": 180, "total_tokens": 600},
    }


@pytest.mark.parametrize("variant", ["executive", "pipeline", "client_health"])
def test_each_variant_returns_a_valid_report(client: TestClient, variant: str) -> None:
    with respx.mock:
        respx.post(GROQ_URL).mock(
            return_value=httpx.Response(200, json=groq_response(provider_report(variant)))
        )

        response = client.post(
            "/reports/dashboard",
            json={"context": context(variant)},
        )

    assert response.status_code == 200
    data = response.json()
    assert len(data["insights"]) == 3
    assert len(data["actions"]) == 4
    assert data["model"] == "openai/gpt-oss-20b"
    assert data["usage"]["total_tokens"] == 600


def test_the_outgoing_payload_is_aggregated_and_bounded(client: TestClient) -> None:
    with respx.mock as mock:
        route = mock.post(GROQ_URL).mock(
            return_value=httpx.Response(200, json=groq_response(provider_report()))
        )

        response = client.post(
            "/reports/dashboard",
            json={"context": context()},
        )

    assert response.status_code == 200

    body = json.loads(route.calls.last.request.content)
    system = body["messages"][0]["content"]
    user = body["messages"][1]["content"]

    assert body["max_tokens"] == 1200
    assert body["reasoning_effort"] == "low"
    assert body["response_format"] == {"type": "json_object"}

    assert "Allowed insight IDs: attention, pipeline, follow_ups, win_rate" in system
    assert "Allowed action IDs: read_answers, open_board, open_worklist, open_clients" in system

    for marker in [
        "Aurora Confidential Holdings",
        "Jane Secret Doe",
        "jane.secret@example.com",
        "09171234567",
        "Secret rate-sheet subject",
        "confidential message body",
    ]:
        assert marker not in user
        assert marker not in system

    parsed = json.loads(user)
    assert parsed["variant"] == "executive"
    assert "name" not in parsed["scope"]
    assert len(parsed["line"]["labels"]) <= 5
    assert len(parsed["pipeline"]["stages"]) <= 5


def test_missing_key_returns_503_without_calling_groq(client: TestClient) -> None:
    app.dependency_overrides[get_settings] = lambda: make_settings(groq_api_key="")

    with respx.mock as mock:
        response = client.post("/reports/dashboard", json={"context": context()})

    assert response.status_code == 503
    assert response.json()["detail"]["reason"] == "not_configured"
    assert not mock.calls


def test_rate_limit_returns_429(client: TestClient) -> None:
    with respx.mock:
        respx.post(GROQ_URL).mock(
            return_value=httpx.Response(429, json={"error": "rate limited"})
        )

        response = client.post("/reports/dashboard", json={"context": context()})

    assert response.status_code == 429
    assert response.json()["detail"]["reason"] == "rate_limited"


def test_provider_error_returns_502(client: TestClient) -> None:
    with respx.mock:
        respx.post(GROQ_URL).mock(
            return_value=httpx.Response(500, json={"error": "boom"})
        )

        response = client.post("/reports/dashboard", json={"context": context()})

    assert response.status_code == 502
    assert response.json()["detail"]["reason"] == "provider_error"


def test_timeout_returns_504(client: TestClient) -> None:
    with respx.mock:
        respx.post(GROQ_URL).mock(side_effect=httpx.TimeoutException("timed out"))

        response = client.post("/reports/dashboard", json={"context": context()})

    assert response.status_code == 504
    assert response.json()["detail"]["reason"] == "timeout"


def test_invalid_json_returns_502(client: TestClient) -> None:
    with respx.mock:
        respx.post(GROQ_URL).mock(
            return_value=httpx.Response(
                200,
                json={"choices": [{"message": {"content": "not json"}}]},
            )
        )

        response = client.post("/reports/dashboard", json={"context": context()})

    assert response.status_code == 502
    assert response.json()["detail"]["reason"] == "invalid_response"


def test_invented_id_returns_502(client: TestClient) -> None:
    report = provider_report()
    report["actions"][0]["id"] = "invented_route"

    with respx.mock:
        respx.post(GROQ_URL).mock(
            return_value=httpx.Response(200, json=groq_response(report))
        )

        response = client.post("/reports/dashboard", json={"context": context()})

    assert response.status_code == 502
    assert response.json()["detail"]["reason"] == "invalid_response"


def test_url_or_email_in_prose_returns_502(client: TestClient) -> None:
    report = provider_report()
    report["insights"][0]["body"] = "Email jane@example.com for the numbers."

    with respx.mock:
        respx.post(GROQ_URL).mock(
            return_value=httpx.Response(200, json=groq_response(report))
        )

        response = client.post("/reports/dashboard", json={"context": context()})

    assert response.status_code == 502
    assert response.json()["detail"]["reason"] == "invalid_response"


def test_wrong_number_of_actions_returns_502(client: TestClient) -> None:
    report = provider_report()
    report["actions"] = report["actions"][:3]

    with respx.mock:
        respx.post(GROQ_URL).mock(
            return_value=httpx.Response(200, json=groq_response(report))
        )

        response = client.post("/reports/dashboard", json={"context": context()})

    assert response.status_code == 502
    assert response.json()["detail"]["reason"] == "invalid_response"
