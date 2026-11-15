"""Agent configuration (pydantic-settings).

Values are read from the environment / `.env`. The agent is a reusable
deployment artifact: it must not contain campaign-specific business logic or
depend on Laravel's internal code or database (STRATEGY.md section 2).
"""
from __future__ import annotations

from uuid import UUID

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="PHISHGUARD_", env_file=".env", extra="ignore")

    # Identity
    agent_id: UUID
    agent_version: str = "1.0.0"

    # RabbitMQ (AMQPS — section 5.5 / 13)
    amqp_url: str = Field(default="amqps://guest:guest@localhost:5671/")
    commands_exchange: str = "phishguard.commands"
    events_exchange: str = "phishguard.events"
    # Campaign-scoped command queue this agent consumes (section 12 queue isolation)
    command_queue: str | None = None
    prefetch_count: int = 10

    # Evidence upload target (section 2 / 7.3)
    evidence_api_base: str = "https://localhost/api"

    # Timing (section 13)
    heartbeat_interval_seconds: int = 30

    # Resource defaults (section 7.2)
    default_cpu_limit: int = 2
    default_memory_limit: str = "2g"

    # Logging
    log_level: str = "INFO"
    log_json: bool = True


def get_settings() -> Settings:
    return Settings()  # type: ignore[call-arg]
