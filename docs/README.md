# Monkey Bot documentation

This folder is internal documentation. nginx denies public access to `/docs` (since 2026-09-22), so nothing here can be downloaded from the website.

## Baseline

- [baseline/2026-09-22-transformation-baseline.md](baseline/2026-09-22-transformation-baseline.md): the Transformation Baseline Report. Sections A–J cover production status, architecture, data model, channels, security, the scheduler, git and deployment, technical debt, migration dependencies and the Phase 0 plan.

## Runbooks

- [runbooks/scheduler.md](runbooks/scheduler.md): the host cron, logs, health, manual ticks, pausing, and the retention warning.
- [runbooks/backup-and-restore.md](runbooks/backup-and-restore.md): DB and config-volume backup and restore. No automated backup exists yet.
- [runbooks/deployment.md](runbooks/deployment.md): live-edit reality, config-volume shadowing, the nginx single-file mount, a safe-change checklist and the target pipeline.

## Security

- [security/webhook-signatures.md](security/webhook-signatures.md): Meta `X-Hub-Signature-256` and internal `X-MB-Internal` checks, with the log → enforce criteria.
- [SECURITY-TASK-D.md](SECURITY-TASK-D.md) and [SECURITY-TASK-E.md](SECURITY-TASK-E.md): earlier security tasks.

## Architecture decisions (ADRs)

- [architecture/adr/ADR-000-strangler-fig-modular-monolith.md](architecture/adr/ADR-000-strangler-fig-modular-monolith.md) (Proposed)
- [architecture/adr/ADR-001-unified-conversation-model.md](architecture/adr/ADR-001-unified-conversation-model.md) (Proposed)
- [architecture/adr/ADR-002-channel-adapter-interface.md](architecture/adr/ADR-002-channel-adapter-interface.md) (Proposed)

## Channels

- [channels/capability-matrix.md](channels/capability-matrix.md): per-channel accounts, webhooks, in/out capability, automation, AI, inbox, handoff, staff hours and scheduler dependency.

## Existing reference material

- [GRAPH_API_MIGRATION.md](GRAPH_API_MIGRATION.md): Graph API version pinning. Phase 3 has not started.
- Sales AI:
  - [SALES-PROMPT-v5.md](SALES-PROMPT-v5.md) (current);
  - [SALES-PROMPT-v4.md](SALES-PROMPT-v4.md);
  - `SALES-PLAYBOOK*.sql`;
  - `danger-suite-v5.py` (adversarial tests).
- [byoot-pricing-config.json](byoot-pricing-config.json): example client pricing config. It contains client commercial data and is internal only.
- `superpowers/`: working plans and specs.
- `../.specs/`: MASTER-PLAN and SPEC-00…31.

## Planned (brief §61)

These documents do not exist yet:

- `architecture/overview.md`
- `architecture/message-lifecycle.md`
- `ai/pipeline.md`
- `automations/engine.md`
- `database/migration-procedure.md`
- `runbooks/incident-recovery.md`
- `runbooks/webhook-troubleshooting.md`

## Status conventions

- **Component status** uses the brief §59 labels: Verified Live, Implemented / awaiting activation, Partial, Broken / Risky, Legacy / Stock.
- **Changes** are reported as "Verified" (with evidence) or "Implemented but not tested" (brief §58).
