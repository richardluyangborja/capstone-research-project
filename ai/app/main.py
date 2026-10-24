"""Primepower AI/ML service.

Lightweight FastAPI service for AI/ML-driven dashboards, management reports, and action
suggestions (see resources/system-ai-feature.md). The backend calls it over HTTP; it is not a
public surface.
"""

import logging

from fastapi import Depends, FastAPI, HTTPException

from .config import Settings, get_settings
from .reports import ReportGenerationError, generate_report
from .schemas import GeneratedReportResponse, ReportRequest

logger = logging.getLogger("uvicorn.error")

app = FastAPI(title="Primepower AI/ML Service", version="0.2.0")


@app.get("/health")
def health() -> dict[str, str]:
    """Liveness probe used by the backend/docker healthcheck."""
    return {"status": "ok"}


@app.post("/reports/dashboard", response_model=GeneratedReportResponse)
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
