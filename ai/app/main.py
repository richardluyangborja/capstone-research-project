"""Primepower AI/ML service.

Lightweight FastAPI service for AI/ML-driven dashboards, management reports, and action
suggestions (see resources/system-ai-feature.md). The backend calls it over HTTP locally and HTTPS
in deployment; the platform may expose it publicly, so every report call requires the shared
`AI_SERVICE_TOKEN`.
"""

import logging
import secrets

from fastapi import Depends, FastAPI, HTTPException, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

from .config import Settings, get_settings
from .reports import ReportGenerationError, generate_report
from .schemas import GeneratedReportResponse, ReportRequest

logger = logging.getLogger("uvicorn.error")

app = FastAPI(title="Primepower AI/ML Service", version="0.3.0")

bearer_scheme = HTTPBearer(auto_error=False)


def require_service_token(
    credentials: HTTPAuthorizationCredentials | None = Depends(bearer_scheme),
    settings: Settings = Depends(get_settings),
) -> None:
    """Reject report calls that do not present Laravel's shared bearer token.

    Fail closed: an unset token is a configuration error, not an open door. The health endpoint
    stays public so the platform can probe the service.
    """

    expected = settings.ai_service_token.strip()

    if expected == "":
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail={
                "reason": "not_configured",
                "message": "AI_SERVICE_TOKEN is not configured.",
            },
        )

    supplied = credentials.credentials if credentials is not None else ""

    if (
        credentials is None
        or credentials.scheme.lower() != "bearer"
        or not secrets.compare_digest(supplied.encode("utf-8"), expected.encode("utf-8"))
    ):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail={
                "reason": "unauthorized",
                "message": "A valid service token is required.",
            },
            headers={"WWW-Authenticate": "Bearer"},
        )


@app.get("/health")
def health() -> dict[str, str]:
    """Liveness probe used by the backend/docker healthcheck."""
    return {"status": "ok"}


@app.post(
    "/reports/dashboard",
    response_model=GeneratedReportResponse,
    dependencies=[Depends(require_service_token)],
)
async def dashboard_report(
    payload: ReportRequest,
    settings: Settings = Depends(get_settings),
) -> GeneratedReportResponse:
    """Generate one management report from Laravel's aggregated, PII-free context."""

    try:
        return await generate_report(payload.context, settings)
    except ReportGenerationError as error:
        logger.warning(
            "management_report.failed variant=%s reason=%s status=%s",
            payload.context.variant,
            error.reason,
            error.status_code,
        )
        raise HTTPException(
            status_code=error.status_code,
            detail={"reason": error.reason, "message": error.message},
        ) from error
