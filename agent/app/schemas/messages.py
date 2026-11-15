"""Standard message envelope (STRATEGY.md section 5.2).

Every command and event exchanged over RabbitMQ is wrapped in this envelope so
that both sides can deduplicate, correlate, isolate by campaign, and validate
against a versioned contract.
"""
from __future__ import annotations

from datetime import datetime, timezone
from typing import Any, Generic, TypeVar
from uuid import UUID, uuid4

from pydantic import BaseModel, ConfigDict, Field

PayloadT = TypeVar("PayloadT", bound=BaseModel)

#: Current contract version. Bump when the envelope or payload shapes change.
SCHEMA_VERSION = 1


def _utcnow() -> datetime:
    return datetime.now(timezone.utc)


class Envelope(BaseModel, Generic[PayloadT]):
    """Common schema for every message (section 5.2).

    Fields:
        message_id:     unique ID for deduplication.
        correlation_id: links a command to its result.
        campaign_id:    campaign isolation.
        agent_id:       originating agent identity.
        event_type:     event or command name (routing key).
        schema_version: contract version.
        timestamp:      UTC timestamp.
        payload:        validated event-specific data.
    """

    model_config = ConfigDict(extra="forbid")

    message_id: UUID = Field(default_factory=uuid4)
    correlation_id: UUID | None = None
    campaign_id: UUID
    agent_id: UUID | None = None
    event_type: str
    schema_version: int = SCHEMA_VERSION
    timestamp: datetime = Field(default_factory=_utcnow)
    payload: PayloadT

    def to_json(self) -> str:
        return self.model_dump_json()

    @classmethod
    def parse(cls, raw: str | bytes | dict[str, Any]) -> "Envelope[PayloadT]":
        if isinstance(raw, dict):
            return cls.model_validate(raw)
        return cls.model_validate_json(raw)
