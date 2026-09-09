# People and relationships

Canonical people model. Overview: [DOMAIN_MODEL.md](DOMAIN_MODEL.md). Projects: [PROJECTS.md](PROJECTS.md).

## CURRENT

There is **no** `people` table.

People appear as:

- Knowledge entities with type `person` (aliases, relations, timeline events);
- Telegram group participants (`telegram_group_participants`) — not Jarvis users;
- synthesis `get_person_status` over that index;
- free-text in memories, tasks, and chat.

Identity is not a resolved Person record. Duplicate names/emails are a known gap.

---

## TARGET

One entity: **`people`**. Do not create a base table per human type (no separate `clients` / `employees` roots).

### Person

Suggested fields (conceptual, not a migration):

- `id`
- `first_name`, `last_name`, `display_name`
- `emails`, `phones`, Telegram identifiers
- `notes`, `status`, preferred language
- `aliases`
- `metadata`

One person may have **several roles at once**. Do not drive the product off a single rigid `type` column.

### Person roles (examples)

`employee`, `client`, `partner`, `contractor`, `supplier`, `advisor`, `lead`, `investor`, `media`, `model`, `brand_contact`, `other`.

### Employee profile

Employees need extra structure. Extension table **`employee_profiles`**, not a second person:

- `person_id`
- `position`, `department`
- `manager_person_id`
- `employment_status`
- `responsibilities`, `areas_of_ownership`
- `notes`

An employee **is** a Person. `EmployeeProfile` only extends.

### Organizations

Store organizations separately: **`organizations`**.

Suggested: `name`, `type`, `description`, `contacts`, `website`, `notes`, `status`.

Examples: companies, agencies, brands, contractors, partners, clients-as-orgs.

**CURRENT:** Knowledge entity type `organization` only.

### Relationships

Need typed links:

- Person ↔ Organization
- Person ↔ Person
- Organization ↔ Organization

Example predicates: `works_for`, `manages`, `reports_to`, `partner_of`, `client_of`, `contractor_for`, `represents`, `collaborates_with`.

SQL schema is Phase 4 work. Cardinality and identity resolution must be designed then.

### Identity resolution

Match on email, phone, Telegram id, aliases, and explicit CEO confirmation.

Admin (and later Workspace) must allow merging duplicates and confirming weak AI matches. Uncertain links stay unconfirmed; they must not silently merge people.

Knowledge Layer person entities should eventually **point at** `people.id` rather than remaining a parallel directory. Migration strategy is an implementation detail of Phase 4 — do not treat Knowledge `person` as the long-term operational Person.
