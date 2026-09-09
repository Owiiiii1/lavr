> **KEEP — completed Owner campaign (JARVIS origin / LAVR core chain).** Not a product vision doc.

# Validation Campaign — Core Daily Workflow

**Status:** COMPLETE — **MANUAL PASS 10/10**. Owner ran a clean repeat campaign in chat
**Validation Core 2**. Scenarios 1–10 all PASS. This is a pass of the tested core daily chain,
not of every subsystem edge case. See [CURRENT_STATE.md](CURRENT_STATE.md) and
[DEFERRED_VALIDATION.md](DEFERRED_VALIDATION.md).
**Closed:** 2026-09-07
**Prepared:** 2026-09-06
**Surface:** Owner Personal Workspace — https://jarvis.owlsolutions.net/jarvis
**Fix commit for the first Scenario 8 LIVE FAIL:** `bcca7ca8ea72181cb6414b2f9d3102178b8b6c63`
**Prepared at HEAD:** see [Cursor_Work_Report.md](Development/Cursor_Work_Report.md)

This is a **product validation** runbook, not a feature plan. Nothing new is being built. The question this
campaign answers is narrow: **do the already-implemented Web/Core subsystems behave as one system when a
real person uses them in one sitting?**

The chain under test:

```
Conversation → Task → Reminder → Watcher → Knowledge → Synthesis → Overview → Notification → state change
```

---

## 1. Scope

**In scope**

| Subsystem | Reference |
| --- | --- |
| Conversation Engine + C.1 conversational intelligence | [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md) |
| Tasks, Reminders, Notifications, B.2 proactive | [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md) |
| Knowledge E.1 | [KNOWLEDGE_LAYER.md](KNOWLEDGE_LAYER.md) |
| Watchers E.2 — **internal sources only** (task / time conditions) | [WATCHERS_AND_AUTOMATIONS.md](WATCHERS_AND_AUTOMATIONS.md) |
| Cross-source Synthesis E.3 | [CROSS_SOURCE_SYNTHESIS.md](CROSS_SOURCE_SYNTHESIS.md) |
| Overview panel («Обзор») | [CROSS_SOURCE_SYNTHESIS.md](CROSS_SOURCE_SYNTHESIS.md) |
| Memory / context budget | [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md), [CONTEXT_BUDGET.md](CONTEXT_BUDGET.md) |
| Ownership / user scoping | this document, §14 |
| Workspace live refresh (no F5) | [CURRENT_STATE.md](CURRENT_STATE.md) §5 |

**Explicitly out of scope for this campaign.** A failure in any of these does **not** fail this campaign, and
passing this campaign does **not** validate any of them:

Gmail live · Google Calendar live · GitHub live · ElevenLabs realtime («Диалог Beta») · Telegram Groups ·
external watcher campaigns · external watcher proposed action → confirmation → external write ·
DST/timezone edge cases · destructive Storage · historical retry/prune · full IDOR/security campaign ·
Mobile / Client API · optional future integrations.

---

## 2. Rules of engagement

**Owner drives. Cursor does not.**

- Every scenario below is performed **by the Owner, by hand, in the production UI**.
- Cursor did **not** simulate this campaign, did not create production records, and did not run any
  destructive command, live provider call, or bulk operation while preparing it.
- Cursor **may not** mark a scenario PASS. Preparation status is `READY`. Only an explicit Owner result
  (`работает` / a described failure) changes a row in the matrix.

**What Cursor may look at when the Owner reports a result**

Allowed: route name, HTTP status, timestamp, entity ids (task / reminder / watcher / knowledge entity /
conversation id), enum values (`status`, `health`, `type`, `mode`), counts, `created_at` / `updated_at`,
exception class, bounded error code from the log.

**Do NOT inspect** (applies to every scenario, repeated per scenario for clarity): message bodies,
conversation transcripts, assembled AI prompts, memory text, knowledge entity summaries or free-text
metadata, Gmail/Calendar/GitHub payloads, tokens, secrets, encrypted credential columns, or any user content
that is not required to decide PASS/FAIL.

**Timing facts that matter while testing**

| Thing | Behaviour |
| --- | --- |
| Knowledge / Memory extraction | asynchronous, queue `analysis,memory`; drained at least once a minute. Re-check after **1–2 minutes**. |
| Watcher evaluation | event-driven on task/reminder/knowledge change, plus `jarvis:watchers:dispatch` every **5 minutes**. |
| Synthesis cache | per-user, TTL ~90s, **version-bumped immediately** on task/reminder/watcher/knowledge/project writes. A change should be visible at once, not after 90s. |
| Live refresh | after a successful chat turn the badges and open panels refetch. **No F5 should ever be needed.** |

---

## 3. Validation matrix

Cursor fills nothing but the first three columns. `Owner result` and `Notes` are filled after the manual run.

| # | Scenario | Subsystems | Status | Owner result | Notes |
| --- | --- | --- | --- | --- | --- |
| 1 | Conversation continuity | Conversation Engine, C.1, Tasks, live refresh | MANUAL PASS | MANUAL PASS | Validation Core 2. Continuation / reference / subtask. |
| 2 | Task + Reminder | Tasks, Reminders, C.1 references, timezone | MANUAL PASS | MANUAL PASS | Create/update reminder. |
| 3 | Internal watcher | Watchers E.2 (task source), C.1 routing | MANUAL PASS | MANUAL PASS | Internal task watcher only. |
| 4 | Knowledge | Knowledge E.1, async extraction, provenance | MANUAL PASS | MANUAL PASS | Tested core flow, not all Knowledge edge cases. |
| 5 | Synthesis | Synthesis E.3, project status, grounding | MANUAL PASS | MANUAL PASS | Tested core synthesis. |
| 6 | Waiting / commitments | Synthesis E.3, Knowledge, person resolution | MANUAL PASS | MANUAL PASS | Waiting / explicit commitments. |
| 7 | State change | Tasks, Reminders, Watchers, Synthesis, cache | MANUAL PASS | MANUAL PASS | Task → Reminder → Watcher → Synthesis. |
| 8 | Overview | Overview panel, Synthesis, ownership, presentation | MANUAL PASS | MANUAL PASS | First run LIVE FAIL; `bcca7ca` fixed stale derived state + wording; Validation Core 2 PASS. Workspace Presentation MANUAL PASS. |
| 9 | Memory vs Knowledge | Memory, Knowledge, context budget | MANUAL PASS | MANUAL PASS | Layers stay separate. |
| 10 | Chat delete | Workspace delete contract, E.1–E.3 regression | MANUAL PASS | MANUAL PASS | Durable Tasks / Knowledge / Memory survive. |

Statuses: `READY` → `MANUAL PASS` / `MANUAL PARTIAL` / `LIVE BUG` → `READY FOR REVALIDATION`.

---

## 4. Preflight

Do this once, before Scenario 1.

1. Open https://jarvis.owlsolutions.net/jarvis and confirm you are in the Owner Workspace.
2. Note the current counts on the header badges: **Задачи**, **Автоматизации**, **Напоминания**,
   **Уведомления**. Write them down — several scenarios compare against this baseline.
3. Open **Обзор** once and close it. It should load without an error strip.
4. Confirm your timezone in **Настройки → Profile** (reminders are created in that zone).
5. Create a new chat from the sidebar and rename it **`Validation Core`**. Every scenario except where noted
   happens in this chat, which is deleted in Scenario 10.

If preflight itself fails, stop and report — the rest of the runbook assumes a working workspace.

---

## Scenario 1 — CONVERSATION CONTINUITY

**Purpose**
The conversation is durable, C.1 resolves a pronoun to the task it just created, a temporary style request
stays temporary, and one instruction creates exactly one task.

**Preconditions**
Preflight complete. Chat `Validation Core` is open and empty.

**Owner action**
Send these four messages, one at a time, waiting for each reply:

1. `Я сейчас проверяю проект YFS. В рамках этого теста отвечай коротко.`
2. `Создай задачу проверить новый билд завтра.`
3. `А к ней добавь подзадачу проверить авторизацию.`
4. `Покажи её.`

**Expected UI**
- The chat persists in the sidebar under the name `Validation Core` after each turn.
- Replies to messages 2–4 are noticeably short (the temporary style is honoured).
- The **Задачи** badge increases by exactly **1** relative to the preflight baseline (the subtask is a child,
  not a second top-level task) — and it does so **without a page reload**.
- Opening **Задачи** shows `проверить новый билд` with the subtask `проверить авторизацию` nested under it.
- Message 4 describes the same task, not a different one and not a list of all tasks.

**Expected backend state**
- One new `conversations` row for `Validation Core`, `user_id` = Owner.
- Exactly one new parent `tasks` row, `status = open`, `due_at` = tomorrow in the Owner timezone.
- Exactly one new child `tasks` row with `parent_task_id` = the parent id.
- **No** write to `user_assistant_profiles` caused by "отвечай коротко" — the temporary style must not be
  persisted as a permanent profile instruction.

**Do NOT inspect**
Message bodies, the assembled prompt, the assistant profile free text. Ids, counts, `parent_task_id`,
`status`, `due_at` and the profile row's `updated_at` are enough.

**Pass criteria**
All four are true: (a) exactly one parent + one child task, (b) the child is attached to the parent created in
step 2, (c) step 4 refers to that same task, (d) the assistant profile was not rewritten by step 1.

**Failure capture**
Report: which step failed, the visible reply behaviour in one sentence, the task ids shown in **Задачи**, the
timestamp, and the badge count before/after. If the wrong task was referenced, give both ids.

**Cleanup**
None yet. The tasks are needed by Scenarios 2, 3, 5, 7. They are removed in the final cleanup (§16).

---

## Scenario 2 — TASK + REMINDER

**Purpose**
A reminder is created from conversation, linked to the right task, in the Owner's timezone, visible in the
Reminder Center, and reschedulable by reference — with no Telegram requirement anywhere.

**Preconditions**
Scenario 1 done. The build-check task exists and is open.

**Owner action**
In the same chat:

1. `Напомни мне завтра в 10 проверить эту задачу.`
2. Open **Напоминания** and confirm what you see (see below), then close it.
3. `Перенеси напоминание на 11.`

**Expected UI**
- **Напоминания** badge increases by 1 without a reload.
- The Reminder Center shows the reminder for **tomorrow 10:00 in your timezone** — not UTC, not 08:00/09:00.
- After step 3 the same reminder now reads **11:00**. A second reminder is **not** created.
- **Задачи** still shows the build-check task open and unchanged; the count did not move.
- No prompt, banner, or error anywhere asks you to connect Telegram.

**Expected backend state**
- One new `reminders` row: `user_id` = Owner, `status = scheduled`, `timezone` = the Owner timezone,
  `original_local_time` = tomorrow 10:00 local, `run_at` = the correct UTC instant.
- `task_id` points at the build-check task **if** the assistant linked it (a link is expected here because the
  message says "эту задачу"; an unlinked reminder is a partial, not a hard fail — record it as such).
- After step 3: **the same** `reminders` row, `run_at` / `original_local_time` moved to 11:00 local. Row count
  unchanged.

**Do NOT inspect**
Reminder text beyond confirming it is the right one in the UI, delivery payloads, push subscription keys.

**Pass criteria**
One reminder row, correct local time, correct timezone string, reschedule mutates that same row, Task Center
unaffected, and nothing required Telegram.

**Failure capture**
Report: reminder id, the time shown in the UI, your timezone, whether a duplicate appeared, and the route +
timestamp if the panel showed an error strip.

**Cleanup**
None yet — Scenario 7 checks what happens to this reminder when the task is completed.

---

## Scenario 3 — INTERNAL WATCHER

**Purpose**
A conditional future check becomes a **Watcher**, not a Reminder; it is bound to the right task, uses an
internal source only, and does not fire immediately on existing state.

**Preconditions**
Scenarios 1–2 done.

**Owner action**
In the same chat:

1. `Если эта задача завтра всё ещё будет открыта, сообщи мне.`
2. Open **Автоматизации**, confirm the entry, close it.
3. Wait a few minutes and check **Уведомления**.

**Expected UI**
- **Автоматизации** badge increases by exactly 1, live.
- **Напоминания** does **not** increase — this must not be silently turned into a second reminder.
- The watcher entry names the build-check task and reads as an internal task/time condition. There is no
  Gmail / Calendar / GitHub source on it and no request to connect an integration.
- Within the first minutes, **Уведомления** does **not** receive a trigger for this watcher.

**Expected backend state**
- One new `watchers` row: `user_id` = Owner, `status = active`, `trigger_type` ∈ {`task_state`,
  `time_condition`}, `source_type = task`, `task_id` = the build-check task, `integration_account_id` = NULL.
- `mode` is `one_shot` (a single "is it still open tomorrow" question), or `recurring` only if the phrasing was
  interpreted as a standing check — record which one you got.
- `cursor.baseline_established` becomes `true` on the first evaluation, and `last_triggered_at` stays NULL for
  now: the first check records a baseline instead of firing on historical state.

**Do NOT inspect**
Watcher reaction payloads, notification bodies, any integration account row.

**Pass criteria**
A watcher (not a reminder) exists, is bound to the correct task, has an internal source with no integration
account, and produced no immediate trigger notification.

**Failure capture**
Report: watcher id, task id, what the **Автоматизации** entry says, whether **Уведомления** fired and when. If
a reminder was created instead of a watcher, give the reminder id too.

**Cleanup**
None yet — Scenario 7 depends on this watcher.

---

## Scenario 4 — KNOWLEDGE

**Purpose**
An explicit stated fact reaches the Knowledge layer asynchronously without blocking the chat, is retrievable
by question, carries provenance, does not obviously duplicate, and keeps Project as the canonical domain.

**Preconditions**
Scenarios 1–3 done. Same chat is fine.

**Owner action**

1. Send: `Marco отвечает за дизайн проекта YFS.`
2. Note that the reply arrives promptly — it must not hang waiting for extraction.
3. **Wait 1–2 minutes** (extraction is asynchronous, drained by the analysis queue at least once a minute).
4. Send: `Кто отвечает за дизайн YFS?`
5. Open **Настройки → Knowledge**. Look at **People**, **Projects**, and **Recent activity**.
6. Send the *same* fact again: `Marco отвечает за дизайн проекта YFS.` Wait 1–2 minutes, reopen
   **Настройки → Knowledge**.

**Expected UI**
- Step 2: normal reply latency. No spinner stuck on the turn, no "processing knowledge" stall.
- Step 4: the answer names Marco and ties him to design on YFS.
- Step 5: `Marco` appears under **People**; `YFS` appears under **Projects**; **Recent activity** shows the
  new entries. Opening the `Marco` entity shows a source/provenance reference back to this conversation.
- Step 6: the second statement does **not** produce a visibly duplicated second `Marco` person entity.

**Expected backend state**
- New `knowledge_entities` rows for the person and the project scope, `user_id` = Owner.
- A `knowledge_relationships` row connecting them (a responsibility / works-on style relation), `status =
  active`.
- `knowledge_entity_sources` rows with the source conversation reference — provenance is present, not
  inferred.
- After step 6: the person entity's `source_count` / `last_seen_at` moved, but no second person entity with
  the same normalized name.
- If a `projects` row for YFS exists, the Knowledge project entity indexes it (`project_id` set) rather than
  competing with it.

**Do NOT inspect**
Entity summaries, extracted metadata free text, message bodies. Entity ids, types, normalized-name collisions,
relation type/status, and source counts are enough.

**Pass criteria**
Chat was never blocked; after the async window the relation is retrievable by question **and** visible in
Settings → Knowledge with provenance; repeating the fact reinforces instead of duplicating.

**Failure capture**
Report: how long you waited, the entity ids under **People** / **Projects**, whether the second statement
created a second entity (give both ids), and the timestamp of step 1. If Settings → Knowledge showed an error
strip, give the route and time.

**Cleanup**
Knowledge is durable by design and is **not** deleted with the chat. Final cleanup in §16 covers it.

---

## Scenario 5 — SYNTHESIS

**Purpose**
E.3 answers a broad "state of the project" question with a bounded, grounded synthesis over what actually
exists — not a raw dump and not an invented health score.

**Preconditions**
Scenarios 1–4 done, including the async Knowledge window.

**Owner action**
In the same chat: `Что сейчас по YFS?`

**Expected UI**
The answer is a short, structured synthesis. It should:

- name the open work (the build-check task, and the subtask if relevant);
- mention what is being waited on / watched **only if that is really true** right now;
- mention recent changes that actually happened in this session;
- reference where things came from (task / knowledge / watcher), not float free.

The answer must **not**:

- dump every field of every record;
- invent a completion percentage, a health score, a risk rating, or a deadline nobody set;
- claim blockers that do not exist;
- mention Gmail / Calendar / GitHub activity (nothing live was connected for this campaign).

**Expected backend state**
No new domain rows are required — synthesis is a **derived read layer**. A `GET` to the synthesis route
returns within the normal panel latency. No `waiting_items` table exists and none should appear.

**Do NOT inspect**
The narrative text beyond judging it as a reader, the AI prompt, provider responses.

**Pass criteria**
Bounded and grounded: everything asserted is traceable to a task, reminder, watcher, or knowledge record you
created in Scenarios 1–4, and nothing about project health was fabricated.

**Failure capture**
Report: the one or two claims that were wrong or unfounded (paraphrased, not the whole answer), plus the
timestamp. If synthesis errored, the panel/chat will surface a bounded code such as `synthesis_failed` —
report that code and the time, and Cursor will match it to the server log entry.

**Cleanup**
None.

---

## Scenario 6 — WAITING / COMMITMENTS

**Purpose**
An explicit commitment by another person is captured as a commitment, resolves to that person, and surfaces
as "what I'm waiting for" — without a parallel duplicate subsystem inventing items.

**Preconditions**
Scenario 4 done (Marco exists in Knowledge).

**Owner action**

1. Send: `Marco обещал прислать новый дизайн до пятницы.`
2. Wait 1–2 minutes for async extraction.
3. Send: `Чего я сейчас жду?`
4. Send: `Кто мне что обещал?`
5. Open **Обзор** and look at the **Жду** section.

**Expected UI**
- Step 3 lists the Marco design item, and the Scenario 3 watcher if it is genuinely still pending.
- Step 4 attributes the promise to **Marco**, with the Friday due date, and does not attribute it to you.
- **Обзор → Жду** shows the same items. It must not show a third, differently-worded copy of the same thing.
- Vague chatter is not promoted: nothing you never framed as a promise should appear as a commitment.

**Expected backend state**
- A `knowledge_events` row of the commitment-made kind, `status`/metadata marking it open, with an actor
  reference resolving to the Marco entity and a due date.
- A `knowledge_relationships` waiting-on / committed-to row between you and Marco.
- Source references point back to this conversation.
- **No** new table and no separate "waiting" store — waiting-for is derived at read time.

**Do NOT inspect**
Commitment free text, extraction metadata beyond `status` / actor id / due date.

**Pass criteria**
The commitment exists once, is attributed to the right person, appears in both the chat answer and
**Обзор → Жду**, and no duplicate item describes the same promise twice.

**Failure capture**
Report: what the two answers said in one line each, what **Обзор → Жду** listed, and the knowledge event id if
visible in Settings → Knowledge → Recent activity.

**Cleanup**
None.

---

## Scenario 7 — STATE CHANGE

**Purpose**
The most important scenario. Closing one task must propagate correctly through reminders, watchers, synthesis,
and the Overview — immediately, and without stale cached state.

**Preconditions**
Scenarios 1–6 done. Build-check task open, its reminder scheduled, its watcher active.

**Owner action**

1. Send: `Задача с проверкой билда выполнена.`
2. Open **Задачи**, then **Напоминания**, then **Автоматизации**, then **Обзор** — in that order, closing each.
3. Send: `Что изменилось?`

**Expected UI**
- **Задачи**: the build-check task is completed. The subtask is resolved consistently with it. The badge count
  drops without a reload.
- **Напоминания**: the linked reminder is no longer sitting there as a live future reminder — it follows the
  current task→reminder rule (open linked reminders are cancelled when the task completes). It must not stay
  scheduled to fire for a task that is done.
- **Автоматизации**: the "still open tomorrow" watcher is no longer an active pending check. Its condition can
  never match now, so it must not keep counting as a future match.
- **Обзор**: the task is **not** in "Открытая работа"; the watcher is **not** in "Жду"; the completion shows in
  "Что изменилось".
- Step 3 lists the completion as a recent change.

**Expected backend state**
- `tasks` row: `status = completed`, `completed_at` set.
- Linked open `reminders` row: `status = cancelled` (not `scheduled`).
- The one-shot task watcher: `status = completed`, and `cursor.resolved_reason = task_closed` when it was
  finished by the task closing rather than by firing.
- Synthesis cache version for the Owner was bumped by the task write — the new state is visible immediately,
  not after the ~90s TTL.

**Do NOT inspect**
Notification bodies, message bodies. Statuses, ids, timestamps, and the watcher `cursor` flag are enough.

**Pass criteria**
All four surfaces agree with each other within one refresh: task completed, reminder not left scheduled,
watcher not left pending, Overview and synthesis both reflect the closure. **Any surface still showing the
task as open is a FAIL, and specifically a cache-invalidation FAIL.**

**Failure capture**
Report: which surface disagreed, the task id, reminder id, watcher id, the exact statuses each surface showed,
and whether pressing F5 fixed it (that single fact distinguishes a caching bug from a data bug).

**Cleanup**
None yet.

---

## Scenario 8 — OVERVIEW

**Purpose**
The Overview panel is a truthful, deduplicated, user-scoped summary of the current state.

**Preconditions**
Scenario 7 done.

**Owner action**
Open **Обзор** and read all five sections: **Сегодня и ближайшее**, **Нужно внимание**, **Жду**,
**Что изменилось**, **Открытая работа**. Then, with **Обзор** still open behind it, open **Задачи**, complete
or reopen any one item, close **Задачи**, and look at **Обзор** again.

**Expected UI**
- Sections match what Scenarios 1–7 left behind.
- No completed task listed as open work.
- The same event is not listed twice because it exists both as a Knowledge event and as a watcher occurrence —
  cross-source deduplication is the point of E.3.
- No record belonging to another user, ever.
- Nothing in **Нужно внимание** that you cannot explain from your own data.
- After the panel mutation, **Обзор** reflects it without a page reload.

**Expected backend state**
Read-only. The synthesis endpoint is scoped to the authenticated user; every returned item carries a source
reference to one of your own records.

**Do NOT inspect**
Nothing beyond the panel itself is needed. Do not open other users' data to compare.

**Pass criteria**
Every section is explainable from your own records, there are no duplicates and no stale open items, and the
panel updated after the mutation without F5.

**Failure capture**
Report: the section, the item title as shown, why it is wrong (stale / duplicated / unexplained), and the
timestamp. For a duplicate, say which two subsystems you think produced it.

**Cleanup**
Undo whatever you toggled in the second half of this scenario.

---

## Scenario 8.1 — OVERVIEW REVALIDATION (after the live bug)

**Status:** MANUAL PASS on campaign **Validation Core 2** (2026-09-07), after the first-run LIVE FAIL.

**What the Owner saw**
After Scenario 7 completed the build task and its subtask, cancelled the linked reminder and
resolved the one-shot watcher, chat synthesis was correct but **Обзор** still showed:

- «Сегодня и ближайшее»: «Проверить задачу «Проверить авторизацию»» and «Задача #254 все еще открыта»
- «Нужно внимание»: «Depends on Задача #253» / «Explicit depends_on relationship is still active»
- «Жду»: «Задача #254 все еще открыта» / «One-shot watcher still waiting for its event»
- «Что изменилось»: the same completion listed twice

**What was fixed**
Derived slices now ask the task table instead of trusting the knowledge graph, one semantic
event produces one card, and every user-facing string goes through the presentation layer
described in [WORKSPACE_PRESENTATION.md](WORKSPACE_PRESENTATION.md). Shipped in
`bcca7ca8ea72181cb6414b2f9d3102178b8b6c63`.

**Revalidation result**
Owner repeated the chain on a clean chat **Validation Core 2**. Scenario 8 is **MANUAL PASS**.
Workspace Presentation is **MANUAL PASS** after that live revalidation. This does not claim
every Overview edge case outside the campaign.

**Owner action**
Repeat Scenario 7 on a fresh pair (a parent task with one subtask, a reminder on the subtask,
and a task watcher), complete the work from **Задачи**, then open **Обзор**.

**Обзор must NOT show**
- the completed subtask as open, upcoming, or waiting
- the resolved one-shot watcher as still waiting
- the cancelled linked reminder on the agenda
- an active dependency or blocker pointing at the completed parent
- the same completion twice, in one section or across two

**Обзор must show**
- the completion once, in «Что изменилось», as a sentence («Задача «…» выполнена»)
- Marco's commitment in «Жду» while it is still open
- the unrelated dentistry task/reminder in «Сегодня и ближайшее» — once, not as both a task
  and its reminder

**Presentation checks (all four panels)**
- no enum values on screen: `task_state`, `overdue_by`, `depends_on`, `works_on`,
  `knowledge_linked`, `task_completed`, `commitment_made`, `one_shot`
- no health string, in particular no «В порядке» / `healthy`
- no `#id` unless two items are otherwise indistinguishable
- a watcher reads as a condition sentence; a reminder shows only its time unless delivery failed
- a task card shows the schedule, not «Открыта»; priority appears only when high or urgent
- a parent task expands to its subtasks with «X из Y подзадач выполнено»
- completing a parent with open subtasks opens the Workspace dialog («Выполнить всё» /
  «Вернуться»), never a browser `confirm`

**Subtask visibility check**
If a parent was completed earlier while a subtask stayed open, that subtask must appear in the
active list on its own, labelled «Подзадача задачи «…»». Nothing open may be invisible.

**Pass criteria**
Every section is explainable from your own records, nothing closed appears as live, nothing is
duplicated, no internal field is on screen, and **Обзор** updates after the mutation without F5.

**Failure capture**
Report the section, the exact line as shown, the task/reminder/watcher ids involved, and the
timestamp. If a line is stale, say which surface disagreed with **Задачи**.

---

## Scenario 9 — MEMORY VS KNOWLEDGE

**Purpose**
Memory and Knowledge are two layers with two jobs. A durable personal preference belongs to Memory; a fact
about a person in a project belongs to Knowledge. Neither should swallow the other.

**Preconditions**
Scenario 4 done.

**Owner action**

1. Send: `Запомни, что я предпочитаю отчёты максимум из пяти пунктов.`
2. Wait 1–2 minutes for async memory processing.
3. Send: `Как я предпочитаю получать отчёты?`
4. Send: `Кто такой Marco в контексте YFS?`
5. Open **Настройки → Memory**, then **Настройки → Knowledge**.

**Expected UI**
- Step 3 answers from the remembered preference (five bullet points maximum).
- Step 4 answers from Knowledge — Marco, design, YFS — and stays factual.
- **Настройки → Memory** lists the preference. **Настройки → Knowledge** does **not** list the preference as a
  person/project entity.
- **Настройки → Knowledge** lists Marco and YFS. **Настройки → Memory** is not where Marco lives.

**Expected backend state**
- A new `memories` row for the preference, `user_id` = Owner, with a source reference to this conversation.
- No new `knowledge_entities` row created for the preference statement.
- The Marco/YFS knowledge records from Scenario 4 unchanged by this scenario.

**Do NOT inspect**
Memory text beyond confirming in the UI that the right kind of thing was stored, revision history contents.

**Pass criteria**
The preference is in Memory and answers step 3; the person fact is in Knowledge and answers step 4; neither
layer stored the other layer's item.

**Failure capture**
Report: which layer stored the wrong thing, the memory id or entity id, and the timestamp.

**Cleanup**
The memory is durable and survives the chat deletion — that is checked in Scenario 10 and cleaned in §16.

---

## Scenario 10 — CHAT DELETE (regression)

**Purpose**
Re-confirm the already-validated Workspace delete contract **after** E.1–E.3 landed. This is a regression
check. It does not reopen Workspace Delete as NOT VALIDATED.

**Preconditions**
Scenarios 1–9 done and their results written into the matrix **before** you delete anything.

**Owner action**

1. Write your results into §3 first. The chat is about to disappear.
2. In the sidebar, open the overflow menu on `Validation Core` → delete → confirm.

**Expected UI**
- The chat disappears from the sidebar immediately, no page reload.
- The workspace switches to another personal chat (or creates `Основной` if none remain).
- **Задачи**, **Напоминания**, **Автоматизации** still contain the records from this campaign — deleting a
  conversation does not delete your work.
- **Настройки → Memory** still contains the Scenario 9 preference.
- **Настройки → Knowledge** still contains Marco and YFS. Their conversation source reference is detached, not
  dangling — opening the entity must not error.
- No error strip anywhere; no FK error.

**Expected backend state**
- The `conversations` row and its `messages` are hard-deleted.
- `tasks`, `reminders`, `watchers` rows survive with their conversation reference cleared, not pointing at a
  deleted row.
- `memories` and `knowledge_entities` survive; their source rows are detached per the provenance rules.

**Do NOT inspect**
Deleted content (it is gone by design), attachment payloads.

**Pass criteria**
Chat and messages gone; tasks / reminders / watchers / memory / knowledge survive; no FK or 500 anywhere; the
UI recovered to another chat without F5.

**Failure capture**
Report: the conversation id, what broke, the route and HTTP status, and the timestamp. A 500 here matters more
than anywhere else in this runbook — capture it precisely.

**Cleanup**
Proceed to §16.

---

## 14. Ownership review (static, already done)

Performed as a code audit, without creating a second live user and without a hostile IDOR campaign — that
campaign stays deferred.

Verified that backend user scoping is authoritative on every read and mutate path in scope: chats, tasks,
reminders, watchers, notifications, knowledge, synthesis (`index`, `project`, `entity`), and workspace status.
Foreign ids return 404 / `not_found`, never data. Owner role grants **capabilities**, not a bypass of
row-level `user_id` scoping — the Personal Workspace handlers scope by the authenticated user in both the
`/jarvis` and `/chat` mounts, and `/chat` is not more permissive than `/jarvis`. AI tools cannot pass
`user_id` or an authorization flag; ids supplied by the model are re-resolved against the caller's own rows.

Gaps found and fixed during preparation are listed in the work report. In short: a reminder could store a
`task_id` the user did not own, a watcher could store an integration account the user did not own, and the
synthesis list route accepted a `project_id` without an explicit `projects` capability check.

**Still deferred:** live A/B two-user isolation campaign, hostile IDOR sweep, C.2, Google/GitHub, Telegram.

---

## 15. Diagnostics available on FAIL

When a scenario fails, this is everything Cursor needs — and it is all safe to share:

| Field | Where the Owner gets it |
| --- | --- |
| Route | the panel that failed, or the URL |
| HTTP status | browser devtools network tab, or the panel error strip |
| Timestamp | the clock, to the minute |
| Entity id | shown in the relevant Center / Settings panel |
| Bounded error code | the panel error strip (e.g. `synthesis_failed`, `not_found`, `capability_denied`) |
| Exception class | Cursor reads it from the server log using your timestamp |

**Never needed:** full messages, AI prompts, Gmail bodies, tokens, secrets.

Synthesis requests now log a bounded, non-sensitive line on unexpected failure (route, user id, synthesis
type, project/entity id, exception class) and return the code `synthesis_failed`, so a failed Overview or
project-status check can be traced from a timestamp alone.

---

## 16. Final cleanup

After the matrix is filled in, remove the campaign's temporary records by hand:

1. **Задачи** — delete or cancel the build-check task and its subtask.
2. **Напоминания** — cancel anything left from Scenario 2.
3. **Автоматизации** — cancel the Scenario 3 watcher if it is still listed.
4. **Уведомления** — mark campaign notifications read.
5. **Настройки → Memory** — remove the "five bullet points" preference if you do not want to keep it.
6. **Настройки → Knowledge** — Marco / YFS are durable knowledge. Keep them if YFS is real; otherwise remove
   the entities from the Knowledge settings panel.
7. The `Validation Core` chat was already deleted in Scenario 10.

Nothing here requires a command line, and nothing here should be done by Cursor.

---

## 17. What a full pass does and does not close

Every scenario is MANUAL PASS on Validation Core 2. The following leave the deferred backlog **only
for the flows actually exercised** — not as “all edge cases validated”:

- B.2 core productivity flow (Tasks / Reminders) as exercised above
- E.1 core Knowledge flow (explicit fact → async extraction → retrieval → provenance)
- E.2 internal watcher flow (task/time source only)
- E.3 synthesis core flow (project status, waiting-for, commitments, recent changes, Overview)
- the specific C.1 behaviours exercised in Scenarios 1–2 (continuation, reference resolution, clarification; no id guessing)
- Workspace Presentation as exercised in Scenario 8 revalidation

Everything else stays deferred, including: ElevenLabs realtime Диалог Beta (C.2), external watcher
campaigns, Gmail live validation, Google Calendar live validation, GitHub live validation (no
separate confirmed manual campaign), Telegram Groups, external watcher proposed action →
confirmation → external write, DST/timezone edge cases, destructive Storage edge cases,
historical retry/prune campaign, full IDOR/security campaign, Mobile / Client API, and optional
future integrations.

See [DEFERRED_VALIDATION.md](DEFERRED_VALIDATION.md).
