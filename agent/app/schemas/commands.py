"""Command payloads — Laravel → Python (STRATEGY.md section 5.3).

Commands are consumed by the agent from `phishguard.commands` with the routing
keys declared in section 5.1.
"""
from __future__ import annotations

from enum import Enum

from pydantic import BaseModel, ConfigDict, Field


class CommandType(str, Enum):
    ACTIVATE = "campaign.activate"
    STOP = "campaign.stop"
    DELETE = "campaign.delete"


class CampaignMode(str, Enum):
    SIMULATION = "simulation"
    INVESTIGATION = "investigation"


# ─── campaign.activate ────────────────────────────────────────────────────────


class BrowserConfig(BaseModel):
    model_config = ConfigDict(extra="forbid")
    engine: str = "chromium"
    novnc_enabled: bool = True


class ResourceConfig(BaseModel):
    model_config = ConfigDict(extra="forbid")
    cpu_limit: int = 2
    memory_limit: str = "2g"


class FeatureConfig(BaseModel):
    model_config = ConfigDict(extra="forbid")
    url_analysis: bool = True
    download_scanning: bool = True
    event_monitoring: bool = True


class ActivateCampaignPayload(BaseModel):
    """Payload for `campaign.activate` (section 5.3)."""

    model_config = ConfigDict(extra="forbid")
    mode: CampaignMode = CampaignMode.INVESTIGATION
    browser: BrowserConfig = Field(default_factory=BrowserConfig)
    resources: ResourceConfig = Field(default_factory=ResourceConfig)
    features: FeatureConfig = Field(default_factory=FeatureConfig)


# ─── campaign.stop ────────────────────────────────────────────────────────────


class StopCampaignPayload(BaseModel):
    """Payload for `campaign.stop` (section 5.3)."""

    model_config = ConfigDict(extra="forbid")
    reason: str = "manual_stop"
    preserve_evidence: bool = True


# ─── campaign.delete ──────────────────────────────────────────────────────────


class DeleteCampaignPayload(BaseModel):
    """Payload for `campaign.delete` (section 5.3).

    Deletion must not destroy evidence subject to retention requirements or
    investigation holds.
    """

    model_config = ConfigDict(extra="forbid")
    remove_containers: bool = True
    remove_temporary_files: bool = True
    preserve_evidence: bool = True
