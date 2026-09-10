# LAVR Phase 6 report — first-class commitments

**Date:** 2026-09-10  
**Repo:** `Owiiiii1/lavr` (`main`)  
**Host path:** `/var/www/lavr`  
**Public URL:** https://lavr.youngfashionshow.com  

No secrets, evidence excerpts, private notes, emails, or transcript text are recorded here.

---

## Status

| Item | Status |
| --- | --- |
| Schema `commitments` / `commitment_evidence` / `commitment_status_history` | **IMPLEMENTED** |
| Lifecycle + effective status (`due_soon` / `overdue` deterministic) | **IMPLEMENTED** |
| Evidence + status history | **IMPLEMENTED** |
| Meeting Intelligence promotion (high → `detected`) | **IMPLEMENTED** |
| Re-analysis idempotency (fingerprint) | **IMPLEMENTED** |
| Manual create → `open` | **IMPLEMENTED** |
| Confirm / dismiss / likely_done / confirmed / cancel / merge | **IMPLEMENTED** |
| Workspace `/lavr/commitments` + detail | **IMPLEMENTED** |
| Admin `/commitments` | **IMPLEMENTED** |
| Today / Person / Project / Meeting integration | **IMPLEMENTED** |
| AI read tools (`list_commitments`, `find_commitment`, `get_commitment`) | **IMPLEMENTED** |
| `get_person_status` first-class order | **IMPLEMENTED** |
| AI mutating tools (confirmation policy) | **IMPLEMENTED** |
| `commitments:refresh-statuses` scheduler | **IMPLEMENTED** |
| In-app notifications + transition dedupe | **IMPLEMENTED** |
| Locales uk / en / ru | **IMPLEMENTED** |
| Feature tests | **IMPLEMENTED** |
| Owner live synthetic workflow | **NOT VALIDATED** |
| Email / Telegram extraction pipelines | **NOT** |
| Telegram outbound commitment alerts | **NOT** |
| Production-wide historical meeting backfill | **NOT** (command exists, dry-run default) |

---

## Schema

Tables: `commitments`, `commitment_evidence`, `commitment_status_history`.

`commitments` stores Person / Project / Meeting / Organization links, title, expected result, `deadline_raw` / `deadline_at` / `deadline_precision`, `lifecycle_status`, persisted effective `status`, `confidence` (`high` / `medium` / `low`), `source_type` (`meeting` / `email` / `telegram` / `manual` / `knowledge_legacy` / `other`), `source_id`, `source_reference` JSON, fingerprint, notification dedupe timestamps, and lifecycle timestamps. Unique `(user_id, fingerprint)`.

Zoom is not a source type; Zoom transcripts become Meetings.

---

## Status model

Durable **lifecycle:** `detected`, `open`, `likely_done`, `confirmed`, `cancelled`, `discarded`.

Computed **effective** (`CommitmentStatusService`): lifecycle plus deadline. `due_soon` / `overdue` only while lifecycle is `open`. Canonical completion status is `confirmed` (not `completed`). Threshold: `config/commitments.php` `due_soon_hours` (default 48). Never LLM for overdue.

---

## Evidence

`commitment_evidence`: type (`promise` / `deadline` / `progress` / `delivery` / `completion` / `confirmation` / `cancellation` / `other`), source, short excerpt, confidence, metadata. `CommitmentEvidenceService` is the hook for later email/Telegram/API.

---

## Lifecycle (Owner)

```text
detected → Confirm → open
detected → Dismiss → discarded (not_a_commitment)
open → due_soon / overdue (scheduler, deterministic)
open → likely_done (completion evidence; not “almost ready”)
likely_done → Owner Mark confirmed → confirmed
any active → cancel (business)
```

Manual create starts at `open`.

---

## Meeting promotion

`CommitmentCandidate` + `CommitmentPromotionService`. Auto after successful Meeting Intelligence:

- high confidence + meaningful action → `detected`
- medium/low → skip auto (Owner can promote from meeting UI)
- unresolved Person → detected with `person_id` null + `unresolved_person=true` (shown in UI)
- inherit meeting `project_id` / `organization_id` only if already set

Fingerprint: user + `meeting:{id}` + person + action + expected result + deadline raw. Same fingerprint → append evidence / refresh unedited `detected` fields. Do not delete operational rows when a later analysis omits the item.

`commitments:scan-meeting-analysis` is dry-run by default. Do not run production-wide without explicit approval.

---

## Dedupe / merge

Deterministic fingerprint only. Admin merge unions evidence/source links, keeps the surviving row, archives the duplicate via `merged_into_id`. No fuzzy AI merge.

---

## Today / Person / Project / Meeting

Today: overdue, due soon, due today — attention-first, not a CRM dashboard. Person: active / overdue / recently confirmed. Project: overdue / due soon / open / recent confirmed. Meeting: promoted rows link to first-class records; unpromoted items stay suggestions.

Deep link allowlist already includes `/lavr/commitments/{id}`.

---

## AI tools

`list_commitments` reads first-class when the Owner has any rows; Knowledge `CommitmentResolver` only if that table is empty. Do not mix sources in one answer. `get_person_status`: Person → first-class commitments → projects → recent meetings → Knowledge if no first-class commitments for that person.

Mutating: `create_manual_commitment`, confirm, mark confirmed, cancel, update deadline. Provider `commitments` → confirmation unless explicit Owner command. No unrestricted create-from-anything tool.

---

## Notifications / refresh

`commitments:refresh-statuses` every 15 minutes. Notifies once per `last_notified_status` transition (open / due_soon / overdue / likely_done). In-app inbox only. Telegram outbound is not in this phase.

---

## Tests

Feature: domain lifecycle, meeting promotion + idempotency, Workspace/Admin/Today/Person/Project, AI tools, Meeting Intelligence paste no longer asserts “no commitments table”, Telegram WebApp `/lavr/commitments` component, `/register` 404, People/Projects/Zoom ingest, locale files uk/en/ru.

Cabinet `/cabinet` chat tests remain a **pre-existing** single-user leftover (not Phase 6). Workspace Chat is `/lavr/chats`.

---

## Manual validation

Synthetic Owner workflow (Person → Project → Meeting → promote → confirm → due soon → overdue → likely_done → confirmed): **NOT VALIDATED** on the live Owner account. Covered by feature tests.

---

## Production deploy

Additive migration `2026_09_10_093000_create_commitments_tables`. Artisan `/usr/bin/php8.5`. `migrate --force`, frontend build, `route:cache`. nginx, Telegram webhook, Zoom webhook unchanged. Scheduler already runs `routes/console.php`. Queue restart only if workers do not pick up new job classes (promotion runs in the existing analysis job).

---

## Known limitations / Phase 7 readiness

- Owner live pass **NOT VALIDATED**.
- Email/Telegram commitment extraction not built; `CommitmentCandidate` is the hook.
- No Telegram outbound due/overdue messages.
- No mass import of Knowledge `commitment_made` events.
- Decisions remain analysis JSON.
- Automation Engine rewrite is Phase 7. Commitment status refresh is a small deterministic notifier, not the engine.

Phase 7 can treat first-class commitment status transitions as operational facts without inventing a second commitment store.
