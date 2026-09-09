# Cross-source Synthesis & Intelligence

> **CURRENT derived view.** `list_commitments` / `get_person_status` are **not** first-class `commitments` / `people` tables. Target: [COMMITMENTS.md](COMMITMENTS.md), [PEOPLE_AND_RELATIONSHIPS.md](PEOPLE_AND_RELATIONSHIPS.md).

**Status.** Phase E.3 **IMPLEMENTED / NOT VALIDATED**. Not MANUAL PASS. Phase E as a whole is **not** complete.

E.1 Knowledge Layer and E.2 Watchers remain **IMPLEMENTED / NOT VALIDATED**.

Synthesis is a **derived read layer**. It answers “what is going on now?” by combining bounded facts from existing domains. It is **not** a second Memory Engine, Knowledge Graph, Tasks system, Project system, autonomous agent, or denormalized dashboard database.

Canonical domains stay authoritative. Synthesis reads them, builds a FactPack, ranks deterministically, and returns grounded DTOs. Optional Analysis AI may add a compact narrative; it never decides state.

See [KNOWLEDGE_LAYER.md](KNOWLEDGE_LAYER.md), [WATCHERS_AND_AUTOMATIONS.md](WATCHERS_AND_AUTOMATIONS.md), [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md), [PROJECTS.md](PROJECTS.md), [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md), [CONTEXT_BUDGET.md](CONTEXT_BUDGET.md).

---

## Product questions

| Question | Type / tool |
| --- | --- |
| Что сейчас происходит по YFS? | `get_project_status` |
| Что изменилось за неделю? | `get_synthesis` `recent_changes` window `7d` |
| Что блокирует проект? | `get_synthesis` `blockers` / project status |
| По кому я жду ответ? | `list_waiting_for` |
| Кому я должен ответить? | `list_commitments` `mine` + waiting |
| Какие обещания я дал? | `list_commitments` `mine` |
| Что зависло? | `get_synthesis` `attention_needed` |
| С кем давно не было контакта? | `get_person_status` (unknown if no indexed interaction) |
| Что важного сегодня / что завтра? | `get_synthesis` `daily_digest` |
| Что изменилось после встречи? | `recent_changes` with window |
| Какие проекты требуют внимания? | `attention_needed` |
| Что мы ждём от Apple? | `list_waiting_for` scoped to person/org |
| Какие внешние зависимости открыты? | waiting-for + blockers with `external` |

---

## Architecture

Pipeline:

1. Resolve scope (user, type, optional project/person, time window).
2. Collect a bounded **FactPack** (`SynthesisFactCollector`). **No Gmail / Calendar / GitHub polling.**
3. Deduplicate (`SynthesisDeduplicator`).
4. Derive waiting-for, commitments, blockers, attention (`WaitingForResolver`, `CommitmentResolver`, `ProjectAttentionResolver`).
5. Assemble recent changes (`SynthesisChangeAssembler`).
6. Deterministic rank (`SynthesisRanker`).
7. Surface unresolved conflicts (`SynthesisConflictDetector`).
8. Optional Analysis AI narrative (`SynthesisNarrativeService`). Failure → structured facts only.
9. Cache by user + type + scope + window (`SynthesisCache`, TTL).

Services live under `app/Services/Synthesis/`. Typed DTOs: `SynthesisScope`, `FactPack`, `SynthesisResult`, `SynthesisItem`, `SourceRef`.

Config: `config/synthesis.php` (FactPack caps, cache TTL, inactivity days, stale hours, context lines).

---

## Synthesis types

Closed vocabulary (`SynthesisType`):

- `project_status`
- `person_status`
- `waiting_for`
- `commitments`
- `blockers`
- `recent_changes`
- `attention_needed`
- `daily_digest`
- `weekly_digest`

No extra vague types.

---

## FactPack budget

Default caps (env-overridable):

| Slice | Default |
| --- | --- |
| projects | 5 |
| people | 10 |
| events | 30 |
| tasks | 30 |
| reminders | 20 |
| watchers | 20 |
| waiting | 20 |
| commitments | 20 |
| changes | 30 |
| attention | 12 |
| summaries | 8 |

Facts are bounded titles, ids, timestamps, statuses. No full email bodies, chat history, calendar descriptions, or raw GitHub payloads.

---

## Waiting-for

Derived view. **No `waiting_items` table.**

Sources (deterministic):

- one-shot watcher still waiting
- open task with an explicit external dependency
- active `waiting_on` Knowledge relation
- explicit open commitment from another actor
- pending expected reply already indexed on the timeline

Each item carries person/entity, project, since, optional due/timeout, source refs, suggested follow-up timing. Semantic derivation only from explicit source text.

When a one-shot watcher completes, the waiting item disappears on the next compute.

---

## Commitments

Not every Task. Explicit language only (`CommitmentLanguage`). Vague phrases (`надо бы`, `maybe`, `когда-нибудь`) are not commitments.

Represented as Knowledge:

- events: `commitment_made` / `commitment_fulfilled` / `commitment_cancelled`
- relations: `waiting_on` / `committed_to`
- metadata: actor, action, optional `due_at`, `status` (`open` / `fulfilled` / `cancelled` / `superseded`), `task_id` when linked

Statuses stay on the event/relation. Completing a Task fulfills a commitment **only** when `metadata.task_id` matches (`CommitmentLifecycle`). No guessing.

Extraction (E.1 Analysis AI) may emit `commitment_made` and those relations when the source text is explicit. Inference-only promises are skipped.

---

## Blockers

Grounded only:

- overdue task that is a prerequisite / still open
- waiting-for external reply
- watcher blocked (disconnected integration / auth)
- project depends on an unresolved task
- explicit “blocked by” Knowledge relation
- calendar/task deadline approaching with incomplete prerequisite

Many open tasks ≠ a blocker. No invented “risk %”.

Project attention labels are facts: Blocked, Waiting external, Deadline risk, Active, No recent activity. No red/yellow/green score.

---

## Recent changes

Default window 7 days unless the user specifies (`7d`, `today`, …). User timezone via existing `SynthesisClock` / profile.

Sources: Knowledge events, task state, reminder changes, watcher occurrences, project updates, conversation summaries, already-indexed integration observations.

Dedup keys (deterministic, no AI):

1. `source_fingerprint` (`fp:…`)
2. knowledge event id
3. watcher occurrence + linked event
4. temporal + entity identity fallback

The same GitHub commit seen as a tool result, Knowledge event, and watcher occurrence appears once.

---

## Attention needed

Derived candidates:

- overdue task
- deadline within 24h / 48h (not already overdue)
- watcher blocked
- unanswered watched thread
- waiting-for older than `waiting_follow_up_days` (default 3)
- project with a recent blocker event
- meeting tomorrow with an open preparation task (when indexed)
- open work + no activity beyond `inactivity_days` (default 7) on an **active** project

Archived / dormant projects are not flagged stale. No-activity attention requires open work, an active watcher, an upcoming deadline, or explicit active status.

Each item: why, source refs, recommended next step (facts deterministic; wording may be AI).

Follow-up suggestions (“Стоит написать Marco”) are suggestions only. No auto message, no auto watcher, no auto task.

Known time → recommend Reminder. Future condition → recommend Watcher. Distinction from E.2 stays.

---

## Authority and conflicts

When sources disagree, the authoritative domain wins:

1. Task domain for task status
2. Project domain for project status
3. Reminder domain for reminders
4. Watcher domain for watcher state
5. Live integration fetch **when the user explicitly asks for current data**
6. Knowledge as indexed evidence
7. Memory as remembered fact

If a conflict remains (no authority, or two Knowledge claims without a domain owner): output `Есть противоречивые данные` plus source refs. Do not silently pick an AI-preferred fact.

Example: Knowledge `task_completed` vs open Task row → Task wins; conflict is still listed as resolved-by-authority.

---

## Freshness

Every result exposes `generated_at`, `data_freshness`, and last-known external observations when relevant (e.g. GitHub last observed 2h ago).

Indexed data is not live. If the user asks “что сейчас в GitHub?” and observation age exceeds `stale_after_hours` (default 6), tool guidance says to use the live GitHub tool. Synthesis itself never polls.

---

## Cache

On-demand compute. Laravel Cache key: user + synthesis type + scope + window + version. TTL default 90s.

Invalidate by bumping a per-user version on Task / Reminder / Knowledge event / Watcher / Project changes. No dependency graph. No historical narrative store. Daily/Weekly Brief records remain the B.2 history; synthesis cache is not a second archive.

---

## Daily Brief / Weekly Review

Existing B.2 scheduler (`jarvis:briefs:dispatch`) stays. No second cron.

`ProductivityBriefCollector` optionally consumes synthesis:

Daily (bounded): today schedule, due/overdue tasks, important watcher occurrences, waiting-for, important project changes, upcoming deadlines, top 3 attention items.

Weekly: what moved / completed / stalled, key project changes, waiting-for, commitments, next-week deadlines, people to follow up, watchers triggered, suggested priorities. Not every event.

If Analysis AI is down, deterministic brief text still delivers.

---

## Proactive engine

Existing `jarvis:proactive:dispatch` may consume top attention / waiting age / project blockers.

Closed extra types: `follow_up`, `project_blocked`, `waiting_too_long`, `deadline_risk`, `stale_project`, `commitment_due`.

B.2 guardrails unchanged: opt-in, daily cap (3), cooldown, quiet hours, unique `dedupe_key`. E.3 does not bypass them and does not create a new notification stream.

---

## Tools

| Tool | Capability | Role |
| --- | --- | --- |
| `get_synthesis` | `knowledge` | Typed synthesis (`type`, optional project/entity, `time_window`) |
| `get_project_status` | `projects` | Cross-source current state. Does **not** replace `get_project_context` |
| `get_person_status` | `knowledge` | Person entity in the user’s graph only |
| `list_waiting_for` | `knowledge` | Derived waiting items |
| `list_commitments` | `knowledge` | `mine` / `others` / `all` |

`get_project_context` remains raw/derived project context. `get_project_status` is current synthesis (blockers, waiting, open work, people, upcoming, freshness, sources).

Regular users: own tasks/reminders/knowledge/watchers/conversations. Owner-only: Projects and Gmail/Calendar/GitHub-derived knowledge. No cross-user joins. No admin bypass in Personal Workspace.

All synthesis tools are read-only. They do not create watchers, tasks, reminders, or external writes.

Compact tool output. No recursive relation dumps.

---

## Conversation / context

Tools-first. Full synthesis is **not** auto-injected every turn.

When C.1 has an `activeProject`, a tiny `synthesis_context` slice may appear: project summary, top blockers, top waiting, recent changes. Caps in `config/synthesis.php` `context` (default 220 tokens / 6 lines).

`ContextBudgetManager` drops `synthesis_context` **first** on overflow — before knowledge, before memories, before the current turn. Memory and the current user message are never crowded out by synthesis.

---

## Timezone

All “today” / “this week” boundaries use the user’s timezone (Owner profile currently Europe/Rome). Weekly review keeps the existing Productivity week-start convention (Sunday 18:00 local unless configured otherwise). Nothing hardcoded globally in synthesis.

---

## HTTP / UI

Routes (Owner `/jarvis` and user `/chat` via the same personal-workspace registrar):

- `GET …/synthesis` → overview payload (`type` query, default `attention_needed`)
- `GET …/synthesis/project/{project}` → project status (projects capability)
- `GET …/synthesis/entity/{entity}` → person/project entity status (own graph)

Workspace Center **Обзор** (`OverviewPanel`): Today / Needs attention / Waiting for / Recent changes. Compact panel, not a BI dashboard.

Knowledge entity card (person/project) shows open loops, waiting, commitments, recent activity, related projects / current status, blockers, open work, people, active watchers — modest, sourced from the same synthesis service.

---

## Open loops

Not “all open tasks.” Candidates:

- explicit unresolved commitment
- unresolved waiting-for
- one-shot watcher still waiting
- task with an explicit external dependency
- unanswered watched thread
- explicit follow-up relation

---

## Migrations

None. Waiting-for is derived. Commitments reuse Knowledge events/relations (string enum columns). Cache is Laravel Cache. No `waiting_items` table.

---

## Production safety

Fakes only in automated tests. Do not call live Gmail/Calendar/GitHub from synthesis. Do not run historical knowledge backfill. Do not run live Analysis AI as part of this milestone. Do not emit production proactive notifications or fire production watchers as a “validation” of E.3.

---

## Not in E.3

Confirmed silent or auto external writes. A second Daily Brief system. CRM scoring. Magic project health percentages. Auto-created watchers/tasks/mail. Giant dashboard DB. Cross-user synthesis. Marking Phase E complete. Inventing Phase E.4 by numbering.
