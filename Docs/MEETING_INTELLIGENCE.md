# Meeting intelligence

Canonical meeting model. Commitments: [COMMITMENTS.md](COMMITMENTS.md). Leadership quality: [LEADERSHIP_REVIEW.md](LEADERSHIP_REVIEW.md).

## CURRENT

There is **no** `meetings` table.

Related pieces:

- Google Calendar is a **live** external source (no local event mirror) — ADR-072;
- Knowledge events may include calendar-related types;
- Telegram group knowledge types include `decision` / `task` / `event_fact`;
- Zoom transcripts are not a first-class import pipeline.

A Zoom transcript must **not** be stored only as a Knowledge document.

---

## TARGET

`meetings` is a first-class operational entity.

Store at least:

- title, date/time
- participants (People)
- project
- original transcript (source of truth for words)
- transcript source (Zoom, upload, …)
- summary, topics
- decisions, extracted commitments, tasks
- open questions, risks
- source links

AI-generated fields must reference the meeting (and preferably transcript location).

### Import pipeline

On transcript load, LAVR should extract:

1. Participants  
2. Topics  
3. Decisions  
4. Tasks  
5. Commitments  
6. Deadlines  
7. Owners  
8. Open questions  
9. Risks  
10. Follow-ups  

Then persist structured rows (Meeting + related operational facts). Original transcript stays.

This is Phase 5. SQL is not specified here.

### Leadership Review

Optional analysis of **execution quality** of the meeting (clarity of owners/deadlines/decisions). Not psychology. [LEADERSHIP_REVIEW.md](LEADERSHIP_REVIEW.md).
