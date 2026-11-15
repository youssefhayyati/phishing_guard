# PhishGuard message contracts

Versioned message contracts shared between the Laravel control plane and the
standalone Python agent (STRATEGY.md §5). These are the source of truth for the
RabbitMQ command/event protocol; both sides validate against them.

> The communication protocol is established **before** browser automation
> (§18) so the agent can be tested independently.

## Transport

Two topic exchanges (§5):

- `phishguard.commands` — Laravel publishes, the agent consumes.
- `phishguard.events` — the agent publishes, Laravel consumes.

Connections use TLS (AMQPS). Queues are durable, messages persistent, with
publisher confirms, manual acks, idempotency by `message_id`, retry queues with
backoff, and dead-letter exchanges (§5.5).

Queues are campaign-scoped internally (§12), e.g.
`phishguard.commands.<campaign-uuid>` / `phishguard.events.<campaign-uuid>`;
the logical event names below are unchanged.

## Envelope

Every message is wrapped in [`envelope.schema.json`](envelope.schema.json) (§5.2).
The `payload` object is validated against the matching file below, keyed by
`event_type`.

## Routing keys (§5.1)

### Laravel → Python (commands)

| Routing key | Payload schema |
|---|---|
| `campaign.activate` | [`commands/campaign.activate.json`](commands/campaign.activate.json) |
| `campaign.stop` | [`commands/campaign.stop.json`](commands/campaign.stop.json) |
| `campaign.delete` | [`commands/campaign.delete.json`](commands/campaign.delete.json) |

### Python → Laravel (events)

| Routing key | Payload schema |
|---|---|
| `campaign.activated` | [`events/campaign.activated.json`](events/campaign.activated.json) |
| `campaign.stopped` | [`events/campaign.stopped.json`](events/campaign.stopped.json) |
| `campaign.deleted` | [`events/campaign.deleted.json`](events/campaign.deleted.json) |
| `evidence.download` | [`events/evidence.download.json`](events/evidence.download.json) |
| `session.notification` | [`events/session.notification.json`](events/session.notification.json) |
| `training.keylog_demo` | [`events/training.keylog_demo.json`](events/training.keylog_demo.json) |
| `training.cookies_demo` | [`events/training.cookies_demo.json`](events/training.cookies_demo.json) |
| `training.credentials_demo` | [`events/training.credentials_demo.json`](events/training.credentials_demo.json) |
| `security.phishing_detected` | [`events/security.phishing_detected.json`](events/security.phishing_detected.json) |
| `evidence.uploaded` | [`events/evidence.uploaded.json`](events/evidence.uploaded.json) |
| `agent.heartbeat` | [`events/agent.heartbeat.json`](events/agent.heartbeat.json) |

## Safety boundary

The `training.*_demo` queues carry **controlled training results only** and must
never carry real browser secrets (§5.1, §11). `password_collected` is pinned to
`false` in the training schemas. Session notifications record **coarse
interaction metadata only** — never typed characters or passwords (§5.4).

The Python implementation of these contracts lives in
[`../agent/app/schemas/`](../agent/app/schemas/) (Pydantic models).
