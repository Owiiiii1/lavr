# Owner context and memory

How LAVR stores and retrieves Owner and business context. It does not copy a client’s private master file into Git.

Runtime: [CURRENT_STATE.md](CURRENT_STATE.md). Product: [PRODUCT.md](PRODUCT.md). Operating loop: [CEO_OPERATING_SYSTEM.md](CEO_OPERATING_SYSTEM.md).

## CURRENT

| Piece | What it actually is |
| --- | --- |
| Memory engine | Durable remembered preferences, instructions, and personal facts for the assistant. Not the business system of record |
| Assistant profile | Name, personality presentation, interaction style, compact `about_user`. This is how the assistant speaks, not a CEO operating profile |
| Owner settings | Timezone, interface locale, assistant locale |
| Directory | People, organizations, roles, relationships |
| Projects | Business contexts and source bindings |
| Operational state | Commitments, meetings, briefs, leadership reviews, proactive events |
| Owner Context V1 | `owner_context_sources` + `owner_context_items`. Dated, source-backed claims. Not a second CRM |

**CURRENT (V1).** Settings → Owner Context imports `.txt` / `.md` into a private file, queues extraction, and stores atomic items with `fact_class` (`fact`, `current`, `historical`, `analysis`, `to_verify`), status, sensitivity, and an optional link to an existing Person, Project, or Organization. A new accepted value does not overwrite an old one: the old row becomes `superseded`. Conflicts and unresolved directory labels stay `needs_review`. `to_verify`, historical, private, restricted, and low-confidence rows are not auto-accepted. Chat receives a short task-scoped pack, not the raw file. Canonical people, projects, organizations, and commitments outrank these claims.

Safe auto-accept requires fact or current (or analysis in a rule/coaching category), confidence at least 0.85, normal sensitivity, a resolved scope when a directory entity is required, no conflict, and no personal-constraint category. The importer never creates a Person, Project, or Organization.

**TARGET:** the five tiers below are not five tables. CEO pattern tracking, a decision ledger, weekly outcomes, KPI definitions, and a lessons ledger are still not built.

Do not send an entire Owner biography into every model prompt. Retrieve only what the turn needs.

## Memory tiers

### TIER 1 — Stable Owner profile

Identity, timezone, preferred languages, communication style, CEO goals, stable operating principles.

**CURRENT:** timezone, locales, assistant profile, plus Owner Context items in categories such as `identity`, `communication`, `ceo_goal`, and `ceo_operating_rule`. **TARGET:** a structured CEO Profile object separate from assistant personality. Items are claims, not that profile.

### TIER 2 — Semi-permanent business context

Org shape, roles, KPI definitions, business model, approved positioning, reporting cadence.

**CURRENT:** People, organizations, directory relationships, projects. **TARGET:** explicit KPI definitions and an approved reporting cadence as their own concepts. Do not treat a chat sentence as a silent KPI change.

### TIER 3 — Project context

Current projects, event plans, negotiations, initiatives.

**CURRENT:** `projects` and bindings to mail, calendar, and Telegram groups. **TARGET:** negotiations and event plans stay project context, not a second CRM.

### TIER 4 — Active operational state

Commitments, deadlines, blockers, decisions, follow-ups, weekly outcomes.

**CURRENT:** commitments, meeting analysis, morning brief, proactive events. Decisions are JSON, not a ledger. Weekly outcomes are **TARGET**.

### TIER 5 — Ephemeral context

Temporary preferences, a passing mood, an outdated draft, a one-off wording constraint.

**CURRENT:** conversation-scoped style is not written to the profile. **TARGET:** ephemeral notes expire and never become FACT.

## Confidence and fact model

| Type | Meaning |
| --- | --- |
| FACT | Stated by the Owner or supported by a source. Cite the source |
| CURRENT | Believed true now, and still timestamped. It can become HISTORICAL |
| HISTORICAL | True earlier. Never answer as if it were still current |
| ANALYSIS | A business or behavior interpretation. Never stored or spoken as an objective fact |
| TO_VERIFY | Possibly useful. It cannot drive a strong action until someone confirms it |

A new fact must not silently overwrite an old fact.

```text
Old state → New state → changed_at → source / evidence
```

Use that for employees, roles, project ownership, pricing, KPIs, event dates, business metrics, and vendors. Meeting Review v2 already keeps superseded transcript items instead of deleting them. The same idea applies here: history stays visible.

UNKNOWN ≠ EMPTY. A missing mailbox or a missing field is not proof that nothing happened.

## Retrieval

Context is task-scoped.

| Question | Retrieve | Do not retrieve |
| --- | --- | --- |
| “What is overdue?” | Commitments, projects, current people | Family context, private history, old personality notes |
| “Help me negotiate this contract.” | Relevant project, approved boundaries, economics already on record | Unrelated private life |
| “Analyze how I ran this meeting.” | Meeting review, prior process findings for this Owner, the chosen operating rule | A causal story about personality or private history |

## Privacy

Keep personal context only when it helps scheduling, priority, communication, coaching, or a decision.

Do not routinely inject family details, intimate information, health details, sensitive personal history, or an emotional history unless the Owner’s current request makes that necessary.

LAVR is not a surveillance system. Personal data is not shown to team members or third parties unless the Owner explicitly requires and permits that disclosure. Logs follow the existing commitment and leadership rules: ids and counts, not private narrative.

## Sensitive context is not an explanation

Do not say the Owner reacted a certain way because of a personal trait or a private history.

Instead:

- describe the observable behavior
- cite the evidence
- compare it with the operating rule the Owner chose
- offer a cautious next step

No diagnosis. ANALYSIS stays labeled ANALYSIS.

## What not to put in Git

A client’s raw master context, private names used as biography, family facts, finances, and health notes are inputs to a private product conversation. They are not repository documentation. Docs use Owner, Manager, Project, and Company.
