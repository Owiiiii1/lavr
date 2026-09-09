# LAVR Phase 4 — People, Organizations, business contexts

**Date:** 2026-09-09  
**Branch:** `main`  
**Remote:** `Owiiiii1/lavr` only  
**Public URL:** https://lavr.youngfashionshow.com  
**Commit intent:** `feat: add LAVR people organizations and business contexts`

Status vocabulary: [CURRENT_STATE.md](../CURRENT_STATE.md).

No secrets, tokens, webhook secrets, emails, phones, or private notes are recorded here.

---

## Result

Phase 4 adds a structured business graph for the single Owner. Canonical **People**, **roles**, **employee profiles**, **organizations**, **relationships**, and **person identities** live in MySQL. Existing **Projects** evolved into business contexts with people/organization pivots and a forward-compatible source-binding table. Knowledge `person` / `organization` entities remain the index layer. Conversation tools read structured rows first. AI cannot silently create directory entities.

Meetings, Zoom, Commitments, Automation Engine rewrite, Executive Brief, multi-mailbox, and SaaS/multi-user were not started.

---

## Schema

| Table | Role |
| --- | --- |
| `people` | Canonical person (`user_id`, names, primary contact fields, `status`, `metadata`) |
| `person_roles` | Many roles per person (enum codes) |
| `person_identities` | Unique `(type, normalized_value)` |
| `employee_profiles` | Extra employee fields; 1:1 with person |
| `organizations` | Canonical organization |
| `directory_relationships` | Typed P↔O / P↔P / O↔O with shape validation |
| `project_people` | Person ↔ Project |
| `project_organizations` | Organization ↔ Project |
| `project_source_bindings` | Forward-compatible source bind (Gmail/Calendar/Telegram/Zoom later) |
| `projects` additive | `category`, `start_date`, `end_date`, `owner_person_id`; status adds `paused`, `completed` |
| `knowledge_entities` additive | nullable `canonical_type`, `canonical_id` |

Migration: `database/migrations/2026_09_09_132116_create_directory_and_business_context_tables.php` (additive, reversible). Email is not unique on `people`.

---

## Models / enums

Models: `Person`, `PersonRole`, `PersonIdentity`, `EmployeeProfile`, `Organization`, `DirectoryRelationship`, `ProjectSourceBinding`. `Project` gained `people()`, `organizations()`, `ownerPerson()`, `sourceBindings()`.

Enums: `PersonStatus`, `OrganizationStatus`, `EmploymentStatus`, `PersonRoleCode`, `PersonIdentityType`, `DirectoryPartyType`, `DirectoryRelationType`, `ProjectSourceType`, `CanonicalEntityType`. `ProjectStatus` includes Active / Paused / Completed / Archived.

---

## Identity strategy

`person_identities` is the lookup key. Primary email/phone/Telegram username on `people` are convenience fields synced into identities. Duplicate identity on another person fails (`identity_taken`). Merge: Admin `PersonMergeService` moves roles, identities, projects, relationships, knowledge canonical ids, then archives the source.

---

## Knowledge linking

Non-destructive. Optional `canonical_type` + `canonical_id` on existing `knowledge_entities`. Admin can link a Knowledge person/org entity to a canonical row. History is not rewritten.

---

## Project evolution

No second `business_projects` table. Existing projects keep chats/topics/memories/groups/tasks. New: people, organizations, dates/category/owner person, source bindings schema. Telegram groups remain the current live source bind.

---

## Admin UI

Custom admin kit (not Filament):

- `/people` list + card: identity, roles, employee profile, organizations/relationships, projects, knowledge links, merge, archive
- `/organizations` list + card
- `/projects` show: business fields + attach people/organizations

Owner policy. Foreign ids → 404.

---

## Workspace UI

Same app for Web and WebApp. Ukrainian-first catalog.

- `/lavr/people` list (search, role, project, status)
- `/lavr/people/{person}`
- `/lavr/organizations`, `/lavr/organizations/{organization}`
- `/lavr/projects` list/detail with people/org counts
- `/lavr/search` lightweight DB search

Meetings / Commitments / Decisions labeled as later phases. No fake overdue data.

---

## AI tools

Read-only: `find_person`, `get_person`, `list_people`, `find_organization`, `get_organization`, `find_project`, `get_project`.

`get_person_status`: canonical Person first, then Knowledge synthesis. No silent writes.

---

## Tests

| File | Coverage |
| --- | --- |
| `tests/Feature/DirectoryPersonTest.php` | Schema, roles, employee profile, identities, relationships, project pivots, merge, archive |
| `tests/Feature/DirectoryAdminTest.php` | Admin CRUD, project attach, auth |
| `tests/Feature/DirectoryWorkspaceTest.php` | Workspace pages, search, 404, uk/en/ru |
| `tests/Feature/DirectoryToolsTest.php` | find/get person/project, person status prefers DB, Knowledge fallback |
| `tests/Feature/ProjectsTest.php` | Schema columns extended |

---

## Manual checks

| Check | Status |
| --- | --- |
| Admin create Person / employee role / position / project / organization | NOT VALIDATED |
| Workspace People list + card | NOT VALIDATED (automated HTTP/Inertia yes) |
| Workspace Projects list + card | NOT VALIDATED (automated HTTP/Inertia yes) |
| Locale switch uk/en/ru on People | IMPLEMENTED (feature test) |
| Chat “who is X / which project” via tools | IMPLEMENTED (mocked tools; no paid AI call) |
| Telegram WebApp auth / `/register` 404 / Chat / Settings | Regression tests exist; this slice did not re-run Owner Mini App E2E |
| Production MySQL backup before migrate | NOT VALIDATED (additive migration; recommend dump if Owner wants a snapshot) |

---

## Known limitations

- No Meetings / Commitments / Decisions tables
- No automatic entity extraction from chat
- No merge wizard UX beyond Admin select+post
- `project_source_bindings` is schema-only; multi-mailbox is Phase 10
- Identity unique is global `(type, normalized_value)` — correct for single Owner
- Admin copy is English-first (same as existing Projects admin)
- Primary organization on list cards uses `works_for` then first project org

---

## Migration notes

Additive. Reversible `down()`. Do not hard-delete operational history. Status/archive preferred. Tests use factories / temporary Owner users; no fake employees seeded into production.

---

## Next: Phase 5A recommendation

First-class `meetings` with participants (canonical Person where resolved), project binding, **manual** transcript upload, original artifact storage, and Meeting Intelligence. Keep Commitments as labeled placeholders unless Phase 6 is pulled forward. Do not start Zoom OAuth in 5A. Reuse People and Projects from this phase; do not invent a parallel attendee table that ignores `people`.

---

## Status board

| Item | Status |
| --- | --- |
| Canonical People | IMPLEMENTED |
| Multiple roles | IMPLEMENTED |
| EmployeeProfile | IMPLEMENTED |
| Organizations | IMPLEMENTED |
| Relationships | IMPLEMENTED |
| Person identities | IMPLEMENTED |
| Project as business context | IMPLEMENTED |
| People↔Projects / Orgs↔Projects | IMPLEMENTED |
| Admin CRUD | IMPLEMENTED |
| Workspace People / Person / Orgs / Project detail | IMPLEMENTED |
| AI structured reads | IMPLEMENTED |
| Knowledge fallback | IMPLEMENTED |
| Localization uk/en/ru (Owner Workspace) | IMPLEMENTED |
| Automated tests | IMPLEMENTED |
| Owner production click-through | NOT VALIDATED |
| Mini App E2E on a real Telegram client | NOT VALIDATED |
