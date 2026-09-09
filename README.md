# LAVR

Personal **AI Chief of Staff** for **one CEO**. Dedicated production instance — not SaaS, not multi-user.

**LAVR** = Leadership · Accountability · Vision · Results (internal principle).

- **Production:** [https://lavr.youngfashionshow.com](https://lavr.youngfashionshow.com)
- **Repository:** [Owiiiii1/lavr](https://github.com/Owiiiii1/lavr)
- **What it is:** [Docs/PRODUCT.md](Docs/PRODUCT.md)
- **What is running:** [Docs/CURRENT_STATE.md](Docs/CURRENT_STATE.md)
- **What to build next:** [Docs/IMPLEMENTATION_PLAN.md](Docs/IMPLEMENTATION_PLAN.md)
- **Doc index:** [Docs/README.md](Docs/README.md)

### Stack (this instance)

PHP **8.5** FPM · Laravel 13 · MySQL database `lavr` · nginx · Inertia/React workspace at `/lavr`.

### Interfaces

| Surface | Role |
| --- | --- |
| Telegram Chat | Primary fast channel (current) |
| Telegram WebApp | Primary rich UI (**target**, same Workspace) |
| Web | `https://lavr.youngfashionshow.com/lavr` (current rich UI) |
| Admin | Technical (`/dashboard`) — not the CEO’s daily UI |

Facts live in the database. AI understands, classifies, synthesizes, and talks. [Docs/DOMAIN_MODEL.md](Docs/DOMAIN_MODEL.md).

Origin: JARVIS. Do not push this tree to `Owiiiii1/JARVIS`.
