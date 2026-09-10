# People and relationships

Canonical people model. Overview: [DOMAIN_MODEL.md](DOMAIN_MODEL.md). Projects: [PROJECTS.md](PROJECTS.md).

## CURRENT (Phase 4)

Operational facts live in structured tables. Knowledge `person` / `organization` entities remain the **index / source layer**. Memory is not the source of roles, companies, or project membership.

### Person (`people`)

One human. Owned by the single Owner (`user_id`). Status: `active` / `inactive` / `archived`. No hard-delete by default.

Fields: `first_name`, `last_name`, `display_name`, `normalized_name`, `primary_email`, `primary_phone`, `telegram_username`, `notes`, `preferred_language`, `status`, `metadata`.

Email is **not** unique on `people`. Identifiers live in `person_identities`.

### Roles (`person_roles`)

Enum codes, many per person: `employee`, `client`, `partner`, `contractor`, `supplier`, `advisor`, `lead`, `investor`, `media`, `model`, `brand_contact`, `other`.

### Employee profile (`employee_profiles`)

A Person with role `employee`. Extra fields: `position`, `department`, `manager_person_id`, `employment_status`, `responsibilities`, `areas_of_ownership`, `notes`. Name/email stay on Person.

### Identities (`person_identities`)

Types: `email`, `phone`, `telegram_user_id`, `telegram_username`, `zoom_email`, `other`. Unique on `(type, normalized_value)`. Merge via `PersonMergeService` (Admin action).

### Organizations (`organizations`)

Distinct from Person. Status: `active` / `inactive` / `archived`.

### Relationships (`directory_relationships`)

Typed graph with allowed subject/object shapes (not a generic graph engine): `works_for`, `manages`, `reports_to`, `partner_of`, `client_of`, `contractor_for`, `represents`, `collaborates_with`, `advisor_to`, `other`.

### Knowledge linking

Additive `knowledge_entities.canonical_type` + `canonical_id`. Legacy Knowledge history is not rewritten.

### AI reads

Tools: `find_person`, `get_person`, `list_people`, `find_organization`, `get_organization`, `find_project`, `get_project`. `get_person_status` prefers canonical Person, then first-class commitments, projects, recent meetings, then Knowledge.

AI does **not** silently create People/Organizations/Projects from chat.

### UI

- Admin: `/people`, `/organizations` (custom admin kit)
- Workspace: `/lavr/people`, `/lavr/people/{person}`, `/lavr/organizations`, `/lavr/organizations/{organization}`, `/lavr/search`

Person cards show first-class commitments (active, overdue, recently confirmed). Unresolved meeting names are not auto-created People.

---

## TARGET (later)

Richer Person merge UI. Automatic entity suggestions from mail/meetings. Email/Telegram identity linking for commitment promotion.
