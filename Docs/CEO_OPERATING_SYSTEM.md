# CEO Operating System

**Status: TARGET.** This is the product direction after Phase 12 and the Meeting Review redesign. It is not a shipped domain, not a phase, and not a claim that new tables exist.

Runtime facts stay in [CURRENT_STATE.md](CURRENT_STATE.md). Product frame: [PRODUCT.md](PRODUCT.md). Memory rules: [OWNER_CONTEXT_AND_MEMORY.md](OWNER_CONTEXT_AND_MEMORY.md). Domain sketch: [DOMAIN_MODEL.md](DOMAIN_MODEL.md).

LAVR today is a personal AI Chief of Staff and operational control layer for one Owner. The target is narrower than a new company and wider than a chatbot:

CEO Operating System + AI Chief of Staff + CEO Coach + Operational Control Layer + Decision Support System.

Mission: help the Owner become a more systematic CEO, and raise the quality of how the business is run. Both outcomes matter. Solving today’s operational problem without a reusable management lesson is incomplete. Coaching without a grounded business fact is not allowed.

## Purpose

Do not stop at a good answer. Change the management loop around the Owner.

The system should reduce dependence on the Owner’s memory. It must not train managers to wait for the AI or the CEO to do their thinking.

## Operating loop

```text
REACTION → FACTS → QUESTIONS → ROOT CAUSE → OPTIONS → DECISION
→ OWNER → DEADLINE → RESULT → REVIEW → LEARNING
```

| Step | Purpose | What LAVR observes | What LAVR may ask | Support | Status |
| --- | --- | --- | --- | --- | --- |
| REACTION | Separate the first impulse from the next management move | Owner message, meeting moment, alert | “What happened, before we decide what to do?” | Chat, meeting transcript | CURRENT conversation; TARGET explicit loop |
| FACTS | Ground the turn in source-backed state | Commitments, meetings, calendar, mail, groups, briefs | “Which source says this?” | People, Projects, Meetings, Commitments, source items | **CURRENT** operational core. UNKNOWN ≠ EMPTY |
| QUESTIONS | Force the missing management question | Gaps: owner, deadline, metric, DoD, decision | “Who owns the result? By when? How will we know?” | Meeting review metrics, commitment fields | **CURRENT** partial (owner/deadline coverage). TARGET question set |
| ROOT CAUSE | Distinguish manager, CEO-clarity, process, and external causes | Repeated misses, blockers, reopened topics | “Is this unclear instruction, missing owner, or an outside dependency?” | Commitments, meeting actions, proactive events | **CURRENT** signals exist. TARGET labeled cause class |
| OPTIONS | Show choices, not one leap to a fix | Known constraints from project and sources | “What are the real options, including do nothing?” | Projects, meetings, mail | TARGET structured options |
| DECISION | Record a choice that changes later work | Decision-like lines in meeting JSON, brief items | “Is this decided, or still a hypothesis?” | Meeting Intelligence `decisions` | **CURRENT** inside analysis JSON only. TARGET ledger |
| OWNER | Name one accountable person | `person_id`, unresolved participant, action owner | “Who owns the outcome, not the activity?” | People, commitments, meeting actions | **CURRENT** |
| DEADLINE | Put a time on the promise | `deadline_at` / missing deadline metrics | “What is the review time?” | Commitments, meeting actions | **CURRENT** |
| RESULT | Name the business result, not the activity | Expected result on a commitment; meeting outcome counts | “What will be true when this is done?” | Commitment `expected_result` | **CURRENT** field. TARGET Definition of Done |
| REVIEW | Close the loop on a date | Briefs, leadership review, meeting review | “When do we look at the result?” | Executive Brief, Leadership Review, per-meeting review | **CURRENT** morning brief, weekly process review, per-meeting review v2 |
| LEARNING | Keep a reusable rule, not a slogan | Repeated process gaps | “What rule should the next meeting follow?” | Leadership findings | TARGET lessons ledger. Not a personality note |

## CEO behavior support

TARGET areas. None of these is a personality trait. Each one is a behavior that can be tied to a fact.

- Follow-up questions before jumping to a solution
- Solution-jumping (acting before facts and options)
- Owner clarity
- Deadline clarity
- KPI clarity
- Definition of Done
- Decision closure
- Delegation
- Priority discipline
- Follow-up discipline
- Repeated misses
- CEO bottleneck (the Owner doing a manager’s work)
- Activity reported instead of a result
- Quality criteria
- Numerical discipline

**CURRENT partial support:** per-meeting review v2 already computes owner coverage, deadline coverage, delegation ratio, and indicators `good` / `needs_attention` / `insufficient_data` for the selected person. Weekly Leadership Review computes process ratios. There is no longitudinal CEO-pattern store.

Example of the dual outcome. A manager’s vague update is not only “fix the employee’s task”. The useful turn also asks whether the Owner’s original task had an owner, a KPI, a Definition of Done, and a review point, and whether the Owner started doing the manager’s work.

## CEO intervention model

LAVR may surface, only from observable facts:

- missing owner
- missing deadline
- missing metric
- missing Definition of Done
- unresolved decision
- repeated miss
- CEO solving manager-owned work
- too many priorities
- activity reported instead of a result
- data conflict across sources
- unclear role ownership

An intervention cites the record (commitment, meeting line, source item, brief). It does not invent an operational failure.

Forbidden: personality diagnosis, emotional labeling, psychological scoring, motivational clichés, “you did this because of a personal history”.

## CEO development over time

TARGET sequence:

```text
isolated pattern → repeated pattern → improving pattern → resolved pattern
```

A pattern would remember: pattern key, first observed, last observed, evidence count, related meetings, related commitments, trend note. That subsystem is **not CURRENT**. Do not describe it as a table.

Weekly Leadership Review already compares a period with the previous period for process ratios. That is not this longitudinal model.

## Weekly operating rhythm

TARGET. Not a new brief type beyond what [EXECUTIVE_BRIEF.md](EXECUTIVE_BRIEF.md) already marks as morning-first.

- 3–5 company outcomes for the week
- 1–3 outcomes per manager
- commitments review
- decisions review
- repeated misses
- KPI review
- blockers
- what needs CEO attention

Rule: if there are seven “top” priorities, they are not top priorities. Weekly outcomes are not commitments and not tasks. See [DOMAIN_MODEL.md](DOMAIN_MODEL.md).

## Meeting rhythm

**CURRENT:** after a transcript, Meeting Intelligence plus executive review v2. Optional personal review of `review_subject_person_id`. No score. Superseded hypotheses leave the executive lists.

**TARGET — before the meeting** (Pre-Meeting Brief, not implemented):

- previous commitments
- KPI or last stated result
- open blockers
- unresolved decisions
- suggested questions for the Owner

The Owner should not enter the room trying to remember the last meeting.

**TARGET — after the meeting:**

- decisions (current, not superseded)
- commitments
- owner, deadline, expected result, Definition of Done
- blockers
- next review
- CEO coaching for the selected person
- manager operational review when the meeting is a 1:1

Two layers stay separate. CEO coaching is about how the Owner ran the meeting. Manager operational review is about the result, the evidence, and the next promise. Neither is an employee personality assessment. Allowed process words include preparation, ownership, KPI fluency, diagnosis, execution reliability, initiative, clarity, and result delivery. Forbidden: lazy, toxic, stupid, disloyal, weak personality, psychological diagnosis. No ranking and no leaderboard.

Meeting types (general, 1:1, project, strategy, sales, finance) are **TARGET** labels. They are not implemented modes. Detail: [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md).

## Decision support

TARGET protocol. The Owner remains the decision-maker.

```text
DECISION
GOAL
KNOWN FACTS
UNKNOWNS
OPTIONS
ECONOMICS
UPSIDE
DOWNSIDE
RISKS
REVERSIBILITY
RECOMMENDATION
OWNER
DEADLINE
NEXT ACTION
```

Keep these labels apart: FACT, ASSUMPTION, HYPOTHESIS, ANALYSIS, RECOMMENDATION. A recommendation is not a fact. A hypothesis that a later meeting closed is not a current risk. First-class Decisions are **absent**. Meeting JSON and brief items are the current substitutes.

## Manager operational review

TARGET. Not HR scoring and not a ranking.

After a 1:1, answer: what result was expected, what happened, what KPI evidence exists, how prepared the manager was, which blockers remain, how good the root-cause diagnosis was, which options were proposed, who owns the next step, and what the next commitment is.

Separate four causes: manager failure, CEO clarity failure, process failure, external dependency. Do not collapse them into a person label.

## Lessons learned

TARGET ledger. A lesson has context, source, observed outcome, the lesson itself, a reusable rule, a date, and a related project, person, or decision.

Use it for recurring operational mistakes, CEO development, and process improvement. Do not store generic motivational lines. Not CURRENT.

## Success condition

Owner:

- remembers less by hand
- firefights less
- asks better questions
- closes more loops
- delegates outcomes
- manages from data

Business:

- clearer owners
- fewer lost commitments
- tighter decision closure
- clearer accountability
- stronger KPI discipline
- less operational dependence on the CEO’s memory

## Product principles

1. AI is not the database.
2. Facts require provenance.
3. UNKNOWN ≠ EMPTY.
4. The Owner remains the decision-maker.
5. No unrestricted third-party actions.
6. No personality diagnosis.
7. No employee surveillance.
8. No treating activity as productivity.
9. No silent overwrite of an old fact.
10. No generic praise.
11. CEO development is evidence-based.
12. Reduce information to attention.
13. Reduce dependence on the Owner’s memory.
14. Do not train managers to outsource thinking to the AI or the CEO.
15. Every serious turn should serve the current business problem and a reusable CEO lesson.

## What this document does not authorize

No Phase 13. No HRM, CRM, or ERP. No therapy. No employee rating. No multi-tenant SaaS. No autonomous executive. No replacement of Meeting Review v2 or the weekly Leadership Review. Implementation requires a CURRENT-versus-TARGET gap review first. [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md).
