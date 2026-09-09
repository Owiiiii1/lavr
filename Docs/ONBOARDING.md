# Onboarding

Canonical CEO onboarding. Personalization implementation: [ASSISTANT_PERSONALIZATION.md](ASSISTANT_PERSONALIZATION.md).

## CURRENT

`user_assistant_profiles`: assistant name, personality, interaction style, about_user, `onboarding_status`, `onboarding_step`.

For the **Owner** on this instance, onboarding is stored as **`completed`** by `AssistantProfileService::defaultsFor`. The Owner is **not** sent through third-party / “Знакомство” provisioning. `startOnboarding` is not the CEO business-map flow.

That completed flag is **temporary legacy**: it means “do not run JARVIS user-provisioning onboarding”, not “the business map is collected”.

Default assistant name: **LAVR**. Personality / about_user / interaction_style may still be empty.

There is no structured capture of projects, employees, mailboxes, or alert policies.

---

## TARGET

CEO onboarding builds the **initial business map** and assistant policies.

Collect at least:

- owner name and preferred address;
- assistant name, character, communication style;
- main projects;
- key people and their roles;
- important companies / partners;
- Gmail accounts and **business context** of each;
- calendars;
- Telegram groups;
- morning report preferences;
- critical alert policy;
- follow-up policy;
- which actions require confirmation.

Output: operational records (People, Organizations, Projects, source bindings, policies) plus the assistant profile.

Until Phase 4–8 exist, do not fake this with Memory-only notes as if they were operational facts.

UI: Web / Telegram WebApp / guided Telegram Chat are all acceptable; Admin may finish messy identity links.
