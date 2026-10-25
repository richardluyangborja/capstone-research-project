"""Settings for the AI service.

The provider key belongs here and nowhere else. Laravel sends only the report context; it never
sees `GROQ_API_KEY`. Laravel authenticates with the shared `AI_SERVICE_TOKEN`; the browser never
sees either secret. Settings are read once per process and can be overridden in tests through the
`get_settings` dependency.
"""

from functools import lru_cache
from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict

ENV_FILE = Path(__file__).resolve().parent.parent / ".env"


class Settings(BaseSettings):
    """Runtime configuration, loaded from `ai/.env` and the process environment."""

    ai_provider: str = "groq"
    ai_model: str = "openai/gpt-oss-20b"
    ai_service_token: str = ""
    groq_api_key: str = ""
    groq_base_url: str = "https://api.groq.com/openai/v1"
    ai_timeout_seconds: float = 2.5

    model_config = SettingsConfigDict(
        env_file=ENV_FILE,
        env_file_encoding="utf-8",
        extra="ignore",
    )


@lru_cache
def get_settings() -> Settings:
    """Return the process-wide settings instance (FastAPI dependency)."""

    return Settings()
