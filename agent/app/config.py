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
    # Campaign this agent serves (used as envelope campaign_id — section 5.2).
    campaign_id: UUID

    # RabbitMQ (AMQPS — section 5.5 / 13). Use amqps:// in production; amqp://
    # is accepted for local/dev brokers.
    amqp_url: str = Field(default="amqp://guest:guest@localhost:5672/")
    commands_exchange: str = "phishguard.commands"
    events_exchange: str = "phishguard.events"
    # Campaign-scoped command queue this agent consumes (section 12 queue
    # isolation). Defaults to agent.<agent_id>.commands if unset.
    command_queue: str | None = None
    prefetch_count: int = 10

    # Browser CDP endpoint used to observe coarse session activity (section 7.3).
    # Within the campaign compose network the noVNC container is reachable here.
    browser_cdp_url: str = "http://novnc:9222"

    # Evidence upload target (section 2 / 7.3)
    evidence_api_base: str = "https://localhost/api"

    # Timing (section 13)
    heartbeat_interval_seconds: int = 30
    session_poll_seconds: int = 5

    @property
    def resolved_command_queue(self) -> str:
        return self.command_queue or f"agent.{self.agent_id}.commands"

    # Resource defaults (section 7.2)
    default_cpu_limit: int = 2
    default_memory_limit: str = "2g"

    # Logging
    log_level: str = "INFO"
    log_json: bool = True


def get_settings() -> Settings:
    return Settings()  # type: ignore[call-arg]
