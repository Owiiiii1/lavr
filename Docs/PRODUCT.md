# LAVR — product

Canonical product definition. Runtime facts: [CURRENT_STATE.md](CURRENT_STATE.md). Domain: [DOMAIN_MODEL.md](DOMAIN_MODEL.md). Interfaces: [INTERFACES.md](INTERFACES.md). Decisions: [DECISIONS.md](DECISIONS.md) ADR-266+.

## What LAVR is

LAVR is a **dedicated production instance** of a personal AI Chief of Staff for **one CEO**.

It is not SaaS. It is not multi-tenant. It is not a public multi-user product. There is no third-party registration.

The instance lives at [https://lavr.youngfashionshow.com](https://lavr.youngfashionshow.com). One working client account. One backend / operational core. Three user-facing interfaces (Telegram Chat, Telegram WebApp, standalone Web) plus a technical Admin panel.

LAVR must do more than answer questions and store memory. It must:

- collect information from mail, calendar, Telegram, meetings, documents, and future APIs;
- maintain a structured model of the CEO’s business;
- understand people, projects, meetings, commitments, tasks, decisions, and risks;
- extract agreements from communication without waiting for “put this on control”;
- track execution, expectations, and deadlines;
- produce executive summaries;
- notify the CEO only about events that deserve attention;
- answer “what is the current state of this company / project / person?” from structured facts.

LAVR automatically receives meeting transcripts from connected conferencing providers, starting with Zoom. Manual upload remains the fallback for other meetings. Technical ingest: [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md).

## Product principle

**LAVR = Leadership · Accountability · Vision · Results**

This is an internal product principle, not required UI copy.

| Letter | Meaning |
| --- | --- |
| Leadership | Helps the CEO manage |
| Accountability | Tracks agreements and ownership |
| Vision | Holds the overall picture of the business |
| Results | Helps decisions become outcomes |

## AI is not the database

Facts live in a **structured database**.

AI is used to understand, classify, analyze, synthesize, and talk.

Conversational AI parses intent. It does not own scheduling, watchers, or report delivery. Deterministic execution belongs to the [Automation Engine](AUTOMATION_ENGINE.md).

Operational answers (“what did Kolya promise?”, “what is happening on Chicago?”) must be assembled from the operational core first. Semantic memory and knowledge search are supporting sources, not the source of truth. [Knowledge vs operational data](DOMAIN_MODEL.md#knowledge-vs-operational-data).

## What LAVR does

- Talk with the CEO over Telegram and Web (text and voice).
- Remember durable personal context (Memory Engine — implemented).
- Index unstructured material (Knowledge Layer — implemented as an index, not as People/Meetings/Commitments tables).
- Hold tasks, reminders, watchers, and scheduled reports (implemented; see CURRENT vs TARGET in those docs).
- **Target:** structured People, Organizations, Projects-as-business-contexts, Meetings, Commitments, Decisions, Events, Executive Brief, Leadership Review.

## What LAVR is not

LAVR is **not**:

- a CRM;
- an ERP;
- an HRM;
- a Jira replacement;
- a Slack replacement;
- a Gmail replacement;
- a standalone project-management suite.

LAVR is an **intelligence and operational control layer** on top of existing systems.

If a source system already stores a task, client, or deal, LAVR should integrate through API rather than duplicate that system. Local structured records exist when LAVR needs them for control, briefing, and CEO questions — not to become a second CRM.

## Audience

One CEO. Operators/developers who maintain this instance. Admin is a technical tool, not the CEO’s daily UI.

## Languages

LAVR is **Ukrainian-first**. It remains a **single-owner** instance. Do not build a multi-user locale architecture.

| Code | Language | Role |
| --- | --- | --- |
| `uk` | Ukrainian | Default / primary |
| `en` | English | Supported |
| `ru` | Russian | Supported |

- Ukrainian is the default interface language for the Owner.
- The Owner must be able to choose the UI language manually (`uk` / `en` / `ru`).
- Preferred **assistant** language is a separate setting. UI locale and assistant response language need not be the same.
- LAVR may temporarily reply in the language of the current request when the Owner writes in another supported language. The stored preferred assistant language must not change silently.
- Source data stays in the original language. Translation is a presentation / AI function, not a write to the source of truth.
- Names of people, organizations, projects, files, and original quotes are not auto-localized.
- If a translation is unavailable, fallback language is Ukrainian.

Runtime status: [CURRENT_STATE.md](CURRENT_STATE.md). Interface behavior: [INTERFACES.md](INTERFACES.md). Sources: [DATA_SOURCES.md](DATA_SOURCES.md).

## Origin

LAVR was created from JARVIS (`Owiiiii1/JARVIS`) as a separate product and repository (`Owiiiii1/lavr`). JARVIS multi-user surfaces were removed in Phase 1. Origin history remains in [LAVR_MIGRATION.md](LAVR_MIGRATION.md) and historical docs listed in [README.md](README.md).
