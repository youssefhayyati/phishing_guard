# PhishGuard agent

Standalone Python remote agent (STRATEGY.md §6). One agent is deployed per
campaign server; it manages the campaign lifecycle, talks to the control plane
over RabbitMQ, runs the browser/analysis/evidence containers, and reports
health.

The agent is a **reusable deployment artifact**. It must not contain
campaign-specific business logic or depend on Laravel's internal code or
database (§2).

## Status

Scaffold for the first implementation milestone (§18): message contracts +
agent structure. Messaging, browser, analysis, and evidence modules are stubs
with TODOs — the communication protocol is established before browser
automation so the agent can be tested independently.

## Layout (§6.1)

```
app/
  main.py            Start agent and supervise tasks
  config.py          Settings (pydantic-settings)
  logging.py         Structured logging (structlog)
  messaging/         RabbitMQ connection and delivery
  campaign/          Lifecycle and state management
  browser/           Chromium, noVNC and sessions
  monitoring/        Heartbeats and session events
  analysis/          Phishing detection
  evidence/          Evidence capture and transfer
  training/          Safe synthetic attack demonstrations
  infrastructure/    Docker and health management
  schemas/           Pydantic message validation (mirrors ../contracts)
deployment/          Dockerfile, docker-compose.yml, bootstrap.sh
tests/
```

## Develop

```bash
python -m venv .venv && . .venv/Scripts/activate   # Windows: Git Bash
pip install -r requirements.txt
cp .env.example .env            # then set PHISHGUARD_AGENT_ID etc.
python -m app.main              # scaffold: parks idle until wired (phase 2)
pytest
```

## Message contracts

The RabbitMQ protocol is defined in [`../contracts`](../contracts). The Pydantic
models in [`app/schemas`](app/schemas) implement those contracts and share the
same `event_type` routing keys.

## Safety boundary (§1, §11)

Keylogging/cookie/credential demonstrations use **synthetic lab data** only. The
agent must not export real browser passwords, cookies, session tokens, or
keystrokes. Session notifications are coarse metadata only.
