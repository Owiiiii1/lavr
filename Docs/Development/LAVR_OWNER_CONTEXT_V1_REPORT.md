# Owner Context V1

Date: 2026-09-23. One structured layer on top of Memory, Knowledge, and the directory. Not a new CRM and not the rest of the CEO Operating System.

## What shipped

- `owner_context_sources` and `owner_context_items`
- Private `.txt` / `.md` upload from Settings → Owner Context. The HTTP request stores the file and queues `ExtractOwnerContextSourceJob`
- Atomic items with fact class, category, scope, sensitivity, confidence, and a short evidence excerpt
- Exact match onto an existing Person, Project, or Organization. No automatic directory rows
- Conflict → `needs_review`. No silent overwrite. Supersede sets the old row to `superseded`
- Safe auto-accept only for high-confidence normal fact/current (or analysis in a rule category) with a resolved scope and no conflict. `to_verify`, historical, private, restricted, and personal constraints stay out of that path
- `OwnerContextRetriever` returns a short pack. Chat prepends nothing to the global rules; the pack is appended for the current question
- Admin `/owner-context` shows counts and source status, not file text or item bodies

## Privacy

Raw files stay on the private disk. Logs carry source id, counts, status, duration, and an error category. They do not carry the document or excerpts.

Restricted items are omitted from the default pack. Private items are omitted unless the question is a negotiation or a meeting-coaching turn, and even then only in the categories that turn is allowed to see.

## Limits (still TARGET)

CEO pattern history, first-class Decisions, weekly outcomes, KPI definitions, 1:1 mode, pre-meeting brief, manager operational review, evening brief expansion, lessons learned. PDF import is deferred. The five memory tiers are retrieval language, not five tables.

## Verification

`php8.5 artisan test --compact tests/Feature/OwnerContextTest.php` — 7 tests. Covers upload queueing, classification, conflicts, unresolved people, supersession, archive, retrieval, chat packing, AI failure without logging the source text, and the admin list.
