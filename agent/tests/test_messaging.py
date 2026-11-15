"""Envelope + payload contract tests (STRATEGY.md section 5).

These validate that the Pydantic models in app/schemas accept the example
payloads from the blueprint and round-trip through the standard envelope.
"""
from __future__ import annotations

from datetime import datetime, timezone
from uuid import uuid4

from app.schemas.commands import ActivateCampaignPayload, CampaignMode
from app.schemas.events import (
    ActivationResultPayload,
    SessionAction,
    SessionNotificationPayload,
    TrainingResultPayload,
)
from app.schemas.messages import SCHEMA_VERSION, Envelope


def test_activate_command_roundtrips():
    env = Envelope[ActivateCampaignPayload](
        correlation_id=uuid4(),
        campaign_id=uuid4(),
        event_type="campaign.activate",
        payload=ActivateCampaignPayload(mode=CampaignMode.INVESTIGATION),
    )
    raw = env.to_json()
    back = Envelope[ActivateCampaignPayload].parse(raw)
    assert back.event_type == "campaign.activate"
    assert back.schema_version == SCHEMA_VERSION
    assert back.payload.browser.engine == "chromium"
    assert back.payload.browser.novnc_enabled is True


def test_activation_result_payload():
    p = ActivationResultPayload(agent_version="1.0.0")
    assert p.status == "active"
    assert p.browser_status == "ready"
    assert p.novnc_status == "ready"


def test_session_notification_coarse_only():
    p = SessionNotificationPayload(
        session_id=uuid4(),
        action=SessionAction.PAGE_NAVIGATION,
        timestamp=datetime.now(timezone.utc),
        metadata={"browser": "chromium"},
    )
    # Coarse metadata only — no keystroke/credential fields exist on the model.
    assert "password" not in p.metadata


def test_training_demo_never_collects_secrets():
    p = TrainingResultPayload(
        exercise="credential_harvesting_demo",
        participant="participant-014",
        interaction="submitted_synthetic_credentials",
    )
    assert p.password_collected is False
    assert p.training_result == "completed"
