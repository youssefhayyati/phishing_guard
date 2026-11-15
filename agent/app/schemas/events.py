"""Event payloads — Python → Laravel (STRATEGY.md sections 5.1, 5.3, 5.4).

Events are published by the agent to `phishguard.events`. The `*_demo` payloads
carry controlled training results only and must never carry real browser
secrets (section 5.1 / section 11).
"""
from __future__ import annotations

from datetime import datetime
from enum import Enum
from typing import Any
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field


class EventType(str, Enum):
    ACTIVATED = "campaign.activated"
    STOPPED = "campaign.stopped"
    DELETED = "campaign.deleted"
    EVIDENCE_DOWNLOAD = "evidence.download"
    SESSION_NOTIFICATION = "session.notification"
    KEYLOG_DEMO = "training.keylog_demo"
    COOKIES_DEMO = "training.cookies_demo"
    CREDENTIALS_DEMO = "training.credentials_demo"
    PHISHING_DETECTED = "security.phishing_detected"
    EVIDENCE_UPLOADED = "evidence.uploaded"
    AGENT_HEARTBEAT = "agent.heartbeat"


class Severity(str, Enum):
    INFORMATIONAL = "informational"
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"
    CRITICAL = "critical"


# ─── Lifecycle results (section 5.3) ──────────────────────────────────────────


class ActivationResultPayload(BaseModel):
    """Payload for `campaign.activated` (section 5.3)."""

    model_config = ConfigDict(extra="forbid")
    status: str = "active"
    browser_status: str = "ready"
    novnc_status: str = "ready"
    agent_version: str


class StopResultPayload(BaseModel):
    model_config = ConfigDict(extra="forbid")
    status: str = "stopped"
    evidence_preserved: bool = True


class DeleteResultPayload(BaseModel):
    model_config = ConfigDict(extra="forbid")
    status: str = "deleted"
    containers_removed: bool = True
    temporary_files_removed: bool = True
    evidence_preserved: bool = True


# ─── Session notification (section 5.4) ───────────────────────────────────────


class SessionAction(str, Enum):
    USER_JOINED = "user_joined"
    USER_LEFT = "user_left"
    PAGE_NAVIGATION = "page_navigation"
    CLICK_ACTIVITY = "click_activity"
    FORM_INTERACTION_DETECTED = "form_interaction_detected"
    DOWNLOAD_STARTED = "download_started"
    DOWNLOAD_COMPLETED = "download_completed"
    DOWNLOAD_BLOCKED = "download_blocked"
    SESSION_IDLE = "session_idle"
    SESSION_ERROR = "session_error"


class SessionNotificationPayload(BaseModel):
    """Payload for `session.notification` (section 5.4).

    Record coarse interaction metadata only. Do not capture typed characters or
    passwords.
    """

    model_config = ConfigDict(extra="forbid")
    session_id: UUID
    action: SessionAction
    timestamp: datetime
    metadata: dict[str, Any] = Field(default_factory=dict)


# ─── Heartbeat (section 13) ───────────────────────────────────────────────────


class HeartbeatPayload(BaseModel):
    model_config = ConfigDict(extra="forbid")
    agent_version: str
    status: str = "healthy"
    uptime_seconds: float = 0.0


# ─── Phishing detection (section 10) ──────────────────────────────────────────


class Indicator(BaseModel):
    model_config = ConfigDict(extra="forbid")
    type: str
    severity: Severity
    description: str


class PhishingDetectedPayload(BaseModel):
    """Payload for `security.phishing_detected` (section 10).

    Risk scores should be calibrated and explainable, not treated as proof of
    maliciousness.
    """

    model_config = ConfigDict(extra="forbid")
    url: str
    risk_score: int = Field(ge=0, le=100)
    classification: str
    indicators: list[Indicator] = Field(default_factory=list)


# ─── Evidence (section 4.2 / 5.1) ─────────────────────────────────────────────


class EvidenceUploadedPayload(BaseModel):
    model_config = ConfigDict(extra="forbid")
    evidence_type: str
    storage_key: str
    sha256: str = Field(min_length=64, max_length=64)
    mime_type: str
    file_size: int = Field(ge=0)
    scan_status: str = "pending"


class EvidenceDownloadPayload(BaseModel):
    """Authorized lab download metadata only — never real secrets."""

    model_config = ConfigDict(extra="forbid")
    session_id: UUID
    original_filename: str | None = None
    mime_type: str
    file_size: int = Field(ge=0)
    sha256: str = Field(min_length=64, max_length=64)


# ─── Controlled training demonstrations (section 11) ──────────────────────────


class TrainingResultPayload(BaseModel):
    """Shared shape for the `training.*_demo` events (section 11).

    Synthetic lab data only. `password_collected` must always be False — the
    platform must not export real secrets.
    """

    model_config = ConfigDict(extra="forbid")
    exercise: str
    participant: str
    interaction: str
    password_collected: bool = False
    training_result: str = "completed"
