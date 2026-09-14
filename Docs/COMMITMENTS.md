# Commitments

Canonical commitment model. Tasks: [TASKS.md](TASKS.md). Meetings: [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md). Automation: [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md). Report: [Development/LAVR_PHASE_6_REPORT.md](Development/LAVR_PHASE_6_REPORT.md).

A **Commitment** is one of LAVR’s central entities.

> Someone explicitly or with sufficient confidence undertook to perform an action or deliver a result.

Commitment ≠ Task. Do not merge tables. A commitment may later spawn a Task or Watcher; it is not itself a Task.

---

## CURRENT (Phase 6)

First-class `commitments` exist (2026-09-10). Knowledge `commitment_made` and `CommitmentResolver` remain as a **legacy derived fallback** when the Owner has no first-class rows. Do not mix both sources in one Chat answer.

| Piece | Status |
| --- | --- |
| `commitments` / `commitment_evidence` / `commitment_status_history` | **IMPLEMENTED** |
| Lifecycle + computed effective status (`due_soon` / `overdue` deterministic) | **IMPLEMENTED** |
| Meeting Intelligence promotion (high confidence → `detected`) | **IMPLEMENTED** |
| Manual create → `open` | **IMPLEMENTED** |
| Confirm / dismiss / likely_done / confirmed / cancel / merge | **IMPLEMENTED** |
| Workspace `/lavr/commitments` + Admin `/commitments` | **IMPLEMENTED** |
| Today / Person / Project / Meeting surfaces | **IMPLEMENTED** |
| Executive Brief collector (overdue / due today / likely_done / detected) | **IMPLEMENTED** (Phase 8) |
| Leadership Review metrics (coverage, overdue, unresolved, concentration) | **IMPLEMENTED** (Phase 9) |
| AI read: `list_commitments`, `find_commitment`, `get_commitment`; `get_person_status` prefers first-class | **IMPLEMENTED** |
| AI write: manual create, confirm, mark confirmed, cancel, update deadline (Owner intent / confirmation) | **IMPLEMENTED** |
| `commitments:refresh-statuses` every 15 min | **IMPLEMENTED** |
| In-app notifications on open / due_soon / overdue / likely_done (deduped once per status) | **IMPLEMENTED** |
| Overdue follow-up is a **suggestion** (“нагадати?”); no auto-message to the person | **IMPLEMENTED** (Phase 7) |
| Commitment transitions recorded on `automation_runs` | **IMPLEMENTED** (Phase 7) |
| Telegram outbound commitment alerts to Owner (optional linked channel) | **IMPLEMENTED** (Owner channel only; not to third parties) |
| Email / Telegram extraction pipelines | **NOT** (generic promotion interface is ready) |
| Owner live synthetic workflow | **NOT VALIDATED** |

Config: `config/commitments.php` (`COMMITMENTS_DUE_SOON_HOURS`, default 48).

### Task vs Commitment

| | Task | Commitment |
| --- | --- | --- |
| What | Formal work item in LAVR | A person’s promise / agreement |
| Typical origin | Created by Owner or system | Meeting analysis, later mail/chat, or manual |
| Close | Task workflow | Evidence + Owner confirmation (`confirmed`) |
| Person | Optional | Desired; unresolved is explicit (`person_id` null + `unresolved_person`) |

### Status model

Persisted **lifecycle** (durable): `detected` → `open` → `likely_done` → `confirmed`, or `cancelled` / `discarded`.

Computed **effective** status (stored, recomputed by `CommitmentStatusService`, never by LLM):

| Status | Meaning |
| --- | --- |
| `detected` | AI found a likely promise; not yet an operational fact |
| `open` | Confirmed and active |
| `due_soon` | Lifecycle `open`, deadline within `commitments.due_soon_hours` |
| `overdue` | Lifecycle `open`, `deadline_at` < now, not likely_done/confirmed/cancelled |
| `likely_done` | Completion evidence exists; Owner has not confirmed |
| `confirmed` | Owner confirmed completion (canonical; do not use `completed` as status) |
| `cancelled` | Business cancellation |
| `discarded` | False positive (`not_a_commitment`); not a business cancel |

`due_soon` and `overdue` apply only while lifecycle is `open`.

### Evidence

Separate `commitment_evidence` rows. Types: `promise`, `deadline`, `progress`, `delivery`, `completion`, `confirmation`, `cancellation`, `other`. Short excerpt only (max ~280 chars). Evidence has its own confidence.

### Meeting promotion

After Meeting Intelligence succeeds, `CommitmentPromotionService` reads `commitments_detected`:

- high confidence + meaningful action → create `detected` (Person may be unresolved; UI must show that)
- medium/low → suggestion only until Owner promotes
- fingerprint `user + meeting:{id} + person + action + expected_result + deadline_raw` — re-analysis updates evidence, does not duplicate
- operational edits (`owner_edited_at` or lifecycle past `detected`) are not overwritten
- inherit `meeting.project_id` / `organization_id` only when already set; never assign an AI project guess

Command `commitments:scan-meeting-analysis` is dry-run by default. Do not run production-wide without explicit approval.

### Manual flow

Owner create: Person, action/title, expected result, deadline, project, notes. `source_type=manual`, status `open`.

Detected: Confirm → `open`. Edit & Confirm. Dismiss → `discarded` (`not_a_commitment`). Cancel → `cancelled`.

Completion: `open` → `likely_done` (evidence) → Owner Mark confirmed → `confirmed`. Progress language (“almost ready”) is not completion evidence.

### AI tools

Reads first-class first. Legacy Knowledge `list_commitments` only if the Owner has zero first-class rows. Mutating tools require confirmation unless the turn is an explicit Owner command.

### Privacy

Logs: `commitment_id`, `person_id`, status transition, source type/id, outcome. Do not log excerpts, notes, emails, or transcript text.

---

## TARGET (later)

- Email / Telegram extractors on the same `CommitmentCandidate` interface (Phase 10 / 11)
- Follow-up that asks before writing to third parties ([AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md), ADR-280)
- Automatic likely_done from mail attachments / next meeting (generic `CommitmentEvidenceService` hook exists)
- Fuzzy merge remains Owner/Admin explicit; no AI auto-merge
