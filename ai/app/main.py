"""Primepower AI/ML service.

Lightweight FastAPI service for AI/ML-driven dashboards, management reports, and action
suggestions (see resources/system-ai-feature.md). Kept intentionally minimal; endpoints and
models are added as iterations progress.
"""

from fastapi import FastAPI

app = FastAPI(title="Primepower AI/ML Service", version="0.1.0")


@app.get("/health")
def health() -> dict[str, str]:
    """Liveness probe used by the backend/docker healthcheck."""
    return {"status": "ok"}


@app.get("/api/v1/reports/summary")
def report_summary() -> dict[str, str]:
    """Placeholder for AI/ML-generated summary/action suggestions."""
    return {"status": "not_implemented"}
