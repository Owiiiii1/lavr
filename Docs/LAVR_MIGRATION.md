# LAVR migration note

**Phase:** 1 (product identity + dedicated instance + multi-user surface removal)  
**Date:** 2026-09-09

## Origin and target

| Item | Value |
| --- | --- |
| Origin | JARVIS (`Owiiiii1/JARVIS`) |
| Target product | **LAVR** |
| Deployment type | Dedicated single-client production instance |
| SaaS / multi-tenant | No |
| Third-party user registration | Not supported |
| Current server | this host (`YFS-prod`) |
| Project path | `/var/www/lavr` |
| Public domain | `lavr.youngfashionshow.com` |
| Git remote | `https://github.com/Owiiiii1/lavr.git` |

LAVR is a personal AI assistant for **one client**. It is not a public multi-user product and is not a SaaS platform.

## Multi-user functionality

Product-level multi-user capability is **removed / being removed in this phase**:

- no public registration
- no Owner-created ordinary users
- no impersonation
- no dual Owner `/jarvis` vs User `/chat` workspaces as a product surface
- canonical workspace is `/lavr`

Laravel authentication and the `users` table remain. There is one working client account.

## Documentation

Product/architecture rewrite: **Phase 2 complete** — [PRODUCT.md](PRODUCT.md), [Docs/README.md](README.md), [Development/LAVR_PHASE_2_REPORT.md](Development/LAVR_PHASE_2_REPORT.md). Historical DECISIONS that describe JARVIS multi-user behaviour stay as origin history (banners + ADR-266+). Runtime: [CURRENT_STATE.md](CURRENT_STATE.md).

Do not copy production JARVIS data, `.env`, or client integration credentials into this instance.
