# Onboarding

Canonical CEO onboarding. Personalization implementation: [ASSISTANT_PERSONALIZATION.md](ASSISTANT_PERSONALIZATION.md).

## CURRENT (Phase 12)

Owner **legacy** `user_assistant_profiles.onboarding_status=completed` is unchanged and is **not** reset.

Business-map onboarding is a separate progress row (`business_map_progresses`) and UI `/lavr/setup` (also Settings → Business setup).

| Piece | Status |
| --- | --- |
| Non-blocking wizard (Save & continue later) | **IMPLEMENTED** |
| Today banner `Setup n/9` | **IMPLEMENTED** |
| Existing Owner not forced into a wizard | **IMPLEMENTED** |
| Steps write canonical People / Projects / Organizations / source bindings / productivity settings | **IMPLEMENTED** |
| Source mappings reuse `project_source_bindings` | **IMPLEMENTED** |
| Live Owner completion campaign | **NOT VALIDATED** |

Steps: Owner profile → projects/orgs → people → responsibilities → sources → mappings → Executive Brief prefs → proactivity → review.

Assistant language and UI language remain separate (`uk` / `en` / `ru`, default `uk`).

Legacy JARVIS “Знакомство” chat onboarding remains for non-owner users only (this instance has a single Owner).
