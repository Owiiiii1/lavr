> **CURRENT Workspace copy contract.** Target rich UI is the same Workspace in WebApp: [INTERFACES.md](INTERFACES.md).

# Workspace Presentation

How Jarvis talks to a person inside the Personal Workspace: what a card says, what it stays
silent about, and which words mean which domain action.

This is a presentation contract, not a new domain. Canonical state always stays in the backend
enums (`TaskStatus`, `ReminderStatus`, `WatcherStatus`, `KnowledgeRelationType`). Nothing here
changes what those mean — it only decides the words the user reads instead of them.

## 1. Human-first principle

The Workspace is a product, not an admin console. A person reads sentences about their work.

Never shown in a normal state:

- internal ids (`#253`), enum values (`task_state`, `overdue_by`, `depends_on`, `works_on`)
- `source_fingerprint`, `occurrence`, `cooldown`, delivery adapter names, raw status codes
- watcher health when it is healthy
- the timezone name when it matches the user's own

Internal identifiers are allowed only in three cases: a debug/admin surface, genuine ambiguity
(two things a person cannot otherwise tell apart), or when the user asked for the id.

Machine fields may still travel in the payload — Core and tools need them — but the UI renders
the human field, never the enum.

## 2. Where the words come from

`app/Services/Workspace/Presentation/`:

| Helper | Answers |
| --- | --- |
| `HumanMoment` | «Сегодня, 18:00», «Завтра, 11:00», «6 сент., 18:30», «2 дня» |
| `HumanStatusLabel` | task/reminder/watcher status, priority, subtask progress, problem lines |
| `HumanWatcherDescription` | a watcher as one sentence: condition + reaction |
| `HumanRelationLabel` | a knowledge edge as a sentence about two named things |
| `HumanSynthesisText` | wording for derived synthesis items (Overview, synthesis tools) |

Rules for these helpers:

- Deterministic. No AI call, no second synthesis engine.
- Computed at serialization time. No presentation string is ever stored in the database.
- A helper returns `null` when the honest answer is silence, and the card omits the line.
- `CanonicalStateResolver` resolves a knowledge entity back to its task, so wording and
  filtering both follow the authoritative domain rather than the graph.

## 3. Card grammar

Every card in Tasks, Reminders and Watchers is the same shape (`WorkspaceCard`):

```
Title                        ← the thing itself, in the user's own words
Secondary line               ← when, or the one state that matters
Meta · Meta · Meta           ← short context: project, priority, progress
Problem line                 ← only when something is wrong
[Primary action] [⋯]
```

The section a card sits in already says most of its status, so the card does not repeat it. An
open task in the active list never says «Открыта»; a healthy watcher never says «В порядке».

Shared primitives live in `resources/js/personal-workspace/components/`: `PanelShell`,
`PanelSection`, `WorkspaceCard`, `OverflowMenu`, `ConfirmDialog`, `ChoiceDialog`. Same radius,
padding, title hierarchy, empty state, loading and error treatment across every panel.

## 4. Action hierarchy

One primary action per card — the thing a person almost always wants. Everything else goes in
the ⋯ menu. Only applicable actions are rendered: the backend sends `startable`, `completable`,
`cancellable`, `editable`, `reopenable`, `pausable`, `resumable` and the UI shows nothing it
cannot do.

Destructive and irreversible-looking actions are worded as decisions, not as buttons: «Отменить
задачу» means "decided not to do it", «Отменить напоминание» means "stop reminding me".

## 5. Tasks

Card: title, then the schedule («Завтра, 18:00» / «Сегодня» / «Без срока»). Meta carries the
project, «Высокий приоритет» or «Срочно» (never normal or low), «В работе» when started, and
subtask progress. A subtask whose parent is already closed also shows «Подзадача задачи «…»» —
it is real open work with no parent card to live in, so it is listed on its own.

- Primary: **Выполнить** — the work is done. Never «Готово», which reads as "dismissed".
- ⋯: Начать работу · Изменить · Добавить подзадачу · Отменить задачу · Вернуть в работу
  (the last only for a completed task, using the existing reopen endpoint).

### Subtasks

The parent card owns its subtasks; they are never duplicated as root cards while the parent is
open. Progress reads «1 из 2 подзадач выполнено», and the list expands on demand — a task with
no subtasks has no expander at all. Completed subtasks are muted and struck through; open ones
can be completed inline or edited through their own ⋯. One level deep, matching the domain.

### Completing a parent with open subtasks

No browser `confirm`. A `ConfirmDialog` names what is still open:

> У задачи осталась 1 незавершённая подзадача: «Проверить авторизацию». Что сделать?
>
> **Выполнить всё** · **Вернуться**

«Выполнить всё» closes the subtasks first and then the parent, so the state the Owner hit in
Scenario 7 — a completed parent with a live subtask underneath — is not reachable from the UI.

## 6. Reminders

Card: what to do, then when. «По задаче «…»» appears only when the reminder is attached to a
task. In a normal state the card says nothing about timezone, channel, delivery state or the
word «Запланировано» — that is plumbing.

Shown only when it matters:

- «Не удалось доставить» / «Нет активного канала уведомлений» — a real problem
- «Время указано по Europe/Rome» — only when the reminder's timezone differs from the user's

Actions:

- Primary **Выполнено** only once it is due or delivered; a future reminder needs no primary.
- ⋯: Изменить · Отложить · Отметить выполненным · Отменить напоминание

«Отложить» opens one small choice list (10 минут · 1 час · Завтра · Выбрать время) instead of
four buttons on the card. «Отметить выполненным» closes the loop; «Отменить напоминание» stops
the reminder. Delivered ≠ completed, and the wording keeps them apart.

## 7. Watchers

A watcher is presented as the agreement it encodes, in one sentence:

> Если «Проверить авторизацию» просрочится больше чем на сутки, я сообщу вам.

Secondary line carries only state worth reading: «Ждёт события», «Приостановлено», «Нужно
переподключить Gmail», «Сработало 6 сент., 18:30». Healthy health is never printed, and trigger,
condition and reaction enums never reach the screen.

- ⋯ Active: Приостановить · Отменить. Paused: Возобновить · Отменить.
- Creation: «Создать через чат» is the primary path. The manual form still exists, with the
  trigger/condition/reaction/task-id/cooldown fields behind «Расширенные настройки».

Completed ≠ cancelled: a watcher that fired and finished is not the same as one the user called
off, and the wording distinguishes them.

## 8. Overview

Sections stay: Сегодня и ближайшее · Нужно внимание · Жду · Что изменилось. Every row is a
sentence with an optional reason line; no raw type is printed under a card.

| Bad | Good |
| --- | --- |
| `Completed: Проверить новый билд YFS` + `task_completed` | Задача «Проверить новый билд YFS» выполнена |
| `Задача #254 все еще открыта` | Ждём выполнения «Проверить авторизацию» |
| `One-shot watcher still waiting for its event.` | Если «Проверить авторизацию» просрочится больше чем на сутки, я сообщу вам. |
| `Depends on Задача #253` + `Explicit depends_on relationship is still active` | Работа зависит от завершения «Проверить новый билд YFS» |
| `Marco works_on YFS` + `knowledge_linked` | Marco работает над YFS |
| `Marco обещал…` + `commitment_made` | Marco обещал прислать новый дизайн до пятницы |

One semantic event, one card. A task completion recorded by the task table, by a knowledge
`task_completed` event and by a watcher occurrence is still a single line: canonical task
changes and their knowledge events share the fingerprint `task-change:{task}:{status}`, and the
change feed additionally drops a repeated sentence. Only the change feed dedupes by sentence —
elsewhere two rows may legitimately share a title and differ by time.

Nothing appears in a derived slice against canonical state: a completed task is not upcoming, a
cancelled reminder is not on the agenda, a resolved one-shot watcher is not waiting, and a
`depends_on` edge whose task is closed is history rather than a blocker.

## 9. Chat synthesis

The same helpers wrap the synthesis facts *before* the Conversation AI sees them, so the model
reads «Ждём выполнения «Проверить авторизацию»» rather than an instruction to mention watcher
#254 and its `overdue_by` condition. Structured fields still ride along in the tool result for
machine use; the display text is human-first. There is no second AI pass for rewriting.

## 10. Humanization is not information loss

Three levels, deliberately:

- **Normal** — minimal: «Завтра, 11:00».
- **Problem** — say the problem: «Не удалось доставить уведомление», «Нужно переподключить Gmail».
- **Ambiguity** — add identifying context (project, due time, parent task) before ever falling
  back to an internal id.

## 11. Accessibility

Web Workspace only; the desktop client stays cancelled. Panels work on desktop and mobile
browsers. Menus and dialogs close on Escape and on outside click, are reachable from the
keyboard, and use the `lucide` icons already in the project. No new UI dependency.
