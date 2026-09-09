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

Deeper documentation rewrite is **deferred to the next documentation phase**. Historical DECISIONS / CHANGELOG entries that describe JARVIS multi-user behaviour are kept as origin history. Runtime/current-state docs now describe LAVR.

Do not copy production JARVIS data, `.env`, or client integration credentials into this instance.
