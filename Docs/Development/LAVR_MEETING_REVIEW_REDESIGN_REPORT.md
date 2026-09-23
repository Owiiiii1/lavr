# Meeting review redesign

Date: 2026-09-23.

Meeting Intelligence still extracts facts into the existing analysis JSON. Schema version 2 adds a `review` object after extraction:

1. Normalize and chunk the transcript.
2. Extract and merge facts.
3. Drop superseded, resolved, hypothesis, brainstorm, and discussion-only items from the executive lists. A later overlapping decision closes an earlier question or risk.
4. Hide a commitment that repeats an action. Promotion skips `duplicate_of_action`.
5. Compute counts and coverage in code. There is no composite score.
6. If `review_subject_person_id` matches a participant by person id or exact display name, write leadership indicators, at most five strengths, at most five improvements, and at most three next-meeting lines. Wording is a grounded template in the owner assistant locale.
7. If the person is missing or does not match, facts are still stored and leadership status is `pending_subject` or `skipped`.

Settings: `default_review_person_id`, `auto_generate_leadership_review`. Per-meeting override recomposes the saved analysis and does not upload a new transcript.

The meeting screen leads with metrics and a main insight. Long lists stay behind “Show all”. Evidence is collapsed. Below `md`, action rows are cards.

Weekly `leadership_reviews` are a separate product and were not replaced.
