# LAVR Phase 1 — отчёт

**Дата:** 2026-09-09  
**Ветка:** `main`  
**Remote:** `https://github.com/Owiiiii1/lavr.git` (`Owiiiii1/lavr`)  
**Commit SHA:** `f42cfe68e766861c3d9f4a12665cdd78b478f1dd`

Этот файл — полный отчёт Phase 1. В чат он не дублируется.

---

## A. Server / runtime

| Параметр | Факт на момент работы |
| --- | --- |
| Путь проекта | `/var/www/lavr` |
| Hostname | `YFS-prod` |
| OS | Ubuntu 24.04.4 LTS |
| PHP CLI (artisan / composer в этой сессии) | **8.5.0** — `/home/deploy/.config/herd-lite/bin/php` (php.new herd-lite, musl) |
| PHP system CLI | **8.3.6** — `/usr/bin/php` |
| PHP-FPM | **8.3.6** (`/usr/sbin/php-fpm8.3`), socket `unix:/run/php/php8.3-fpm.sock` |
| PHP 8.4 / 8.5 FPM | **не установлен** |
| Laravel | **13.30.1** |
| nginx | **1.24.0** (Ubuntu) |
| MySQL server на хосте | **8.0.46** |
| БД приложения | **SQLite** `database/database.sqlite` (пользователь `deploy` не может `CREATE DATABASE` в MySQL без sudo) |
| Node | **v20.20.2** |
| npm | **10.8.2** |
| Composer | **2.10.0** (`/usr/local/bin/composer`; запуск через PHP 8.5) |

`.env` (только несекретные ключи): `APP_NAME=LAVR`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://lavr.youngfashionshow.com`. Секреты в отчёт не выводились.

Один рабочий аккаунт клиента создан на этом инстансе. Учётные данные bootstrap лежат на сервере в `storage/app/.owner-bootstrap` (права 0600, gitignored). Пароль в git и в этот отчёт не записывался.

---

## B. Domain / nginx / SSL

| Пункт | Результат |
| --- | --- |
| DNS `lavr.youngfashionshow.com` | Указывает на этот сервер (`5.78.224.87`) |
| nginx vhost установлен в `sites-enabled` | **НЕТ** |
| Итоговый публичный URL приложения | **не поднят** (ожидаемый: `https://lavr.youngfashionshow.com`) |
| HTTP → HTTPS для LAVR | **НЕТ** |
| Let's Encrypt cert для `lavr.youngfashionshow.com` | **НЕТ** |
| `nginx -t` от пользователя `deploy` | **FAIL** — нет прав читать `/etc/letsencrypt/live/app.youngfashionshow.com/fullchain.pem` (тест существующего конфига, не LAVR vhost) |
| `sudo` для установки сайта / certbot / php-fpm | **FAIL** — `sudo: a password is required` |

**Что сделано.** Шаблон vhost лежит в репозитории:

`deploy/nginx/lavr.youngfashionshow.com.conf`

- `server_name lavr.youngfashionshow.com`
- `root /var/www/lavr/public`
- HTTP `:80`, `try_files` Laravel, ACME webroot, deny dotfiles
- `fastcgi_pass unix:/run/php/php8.3-fpm.sock` — это **текущий** socket хоста. Он **несовместим** с этим деревом: Symfony 8 / Laravel 13 требуют PHP ≥ 8.4 (property hooks). Включение vhost на php8.3-fpm даст HTTP 500 даже после `nginx -t`.

**Что видно снаружи сейчас.**

- `http://lavr.youngfashionshow.com` → **200** страница default nginx Ubuntu, не Laravel.
- `https://lavr.youngfashionshow.com` → TLS handshake, сертификат **`CN=app.youngfashionshow.com`**, mismatch SNI. Клиентский curl: SSL name mismatch.

**Команды, которые нужно выполнить от root (владелец сервера):**

1. Установить PHP-FPM ≥ 8.4 (предпочтительно 8.5) и поменять `fastcgi_pass` на его socket.
2. Скопировать vhost в `sites-available` / `sites-enabled`.
3. `nginx -t` и `systemctl reload nginx`.
4. `certbot --nginx -d lavr.youngfashionshow.com --redirect`.

Браузерная проверка публичного домена **не выполнена** (endpoint не отдаёт LAVR). Локальная проверка продукта — через PHP 8.5 + PHPUnit + `php artisan serve` на `127.0.0.1:8088` (см. раздел F).

---

## C. Multi-user removal

### Удалённые / несуществующие product routes

- Публичная регистрация: маршрута `register` нет. `GET/POST /register` → 404.
- User management: нет `settings.users.*`. `POST /settings/users`, `GET /settings/users/{id}` → 404.
- Impersonation: нет `impersonation.stop`. `POST /impersonation/stop` → 404.
- Dual workspace: product-группа больше не регистрируется на `/chat` как живой workspace. Нет `chat.index`.

### Canonical workspace

- `$registerPersonalWorkspace('/lavr', 'jarvis', ['web', 'auth', 'user.active'], true)`
- Имена маршрутов оставлены `jarvis.*` (внутренний слой, не пользовательский бренд).
- Хелпер `app/Support/WorkspaceUrl.php` (`PREFIX = '/lavr'`).

### `/chat` и `/jarvis`

- `GET /jarvis` и `GET /jarvis/{path}` → **302** `/lavr` / `/lavr/{path}`
- `GET /chat` и `GET /chat/{path}` → **302** `/lavr` / `/lavr/{path}`
- `POST /chat/...` → **405** (redirect-маршруты только GET). Обход auth через POST на старый `/chat` как рабочий workspace невозможен.
- Гость на `/lavr` → redirect на login.

### User management UI

Удалены страницы:

- `resources/js/Pages/Settings/UsersPanel.jsx`
- `resources/js/Pages/Settings/UserCard.jsx`
- `resources/js/Pages/Settings/UserMemory.jsx`

Вкладки Users / «Add user» нет в Settings. Impersonation banner убран из Admin / Cabinet / Jarvis layouts. Memory settings больше не ведёт в каталог пользователей.

### Controllers / services (legacy, routes сняты)

Оставлены как unused / `@deprecated`, чтобы не ломать auth-инфраструктуру и не делать опасный большой delete:

- `App\Http\Controllers\Settings\UserController`
- `App\Http\Controllers\Settings\UserMemoryController`
- `App\Services\Users\UserAdministrationService`
- `App\Services\Users\ImpersonationService`

Таблица `users` и Laravel authentication **сохранены**. Роль Owner остаётся для admin/system (`/dashboard`, `/settings`).

### Onboarding

- **Оставлен** personal onboarding ассистента: `POST /lavr/onboarding` (`jarvis.onboarding.start`) — имя, характер, предпочтения, about owner.
- **Убран** onboarding как provisioning стороннего аккаунта (нет admin create-user / register).

Telegram pairing copy переведён на LAVR. Код авторизации Telegram по-прежнему привязывает **существующий** аккаунт клиента, не создаёт второго product-user через публичную регистрацию.

---

## D. Rebranding Jarvis → LAVR (пользовательский слой)

Не переименовывались namespaces `App\Http\Controllers\Jarvis\*`, React `Pages/Jarvis/*`, CSS `jarvis-workspace`, таблица `jarvis_notifications`, имена маршрутов `jarvis.*`, внутренние `JarvisTool` / `JarvisNotification`.

Пользовательский бренд заменён в том числе:

- `APP_NAME` / `config/app.php` default / `config/owl-admin.php` brand → **LAVR**
- Login: «Вход в LAVR»
- Dashboard / AdminLayout: «Open LAVR»
- Default assistant name: `AssistantProfileService::OWNER_DEFAULT_NAME = 'LAVR'`
- Миграция `2026_09_09_091200_rename_default_assistant_name_to_lavr` — только строки с `assistant_name = 'Jarvis'`
- Telegram pairing / conversation strings
- Push / reminder SW / tool prompts / DefaultRolePrompts (user-facing)
- `.env.example`: `APP_NAME=LAVR`, `APP_URL=https://lavr.youngfashionshow.com`, `OWL_ADMIN_BRAND=LAVR`

Внутренние символы `Jarvis*` в PHP/JS **намеренно** оставлены.

---

## E. Documentation

Новые:

- `Docs/LAVR_MIGRATION.md`
- `Docs/Development/LAVR_PHASE_1_REPORT.md` (этот файл)

Обновлены runtime / current-state (без полной переписи):

- `README.md`
- `Docs/README.md`
- `Docs/CURRENT_STATE.md`
- `Docs/PROJECT.md`
- `Docs/ARCHITECTURE.md`
- `Docs/ROADMAP.md`
- `Docs/USERS_AND_CABINET.md` (помечен как origin JARVIS, поверхность снята)
- `Docs/USER_ADMINISTRATION.md`
- `Docs/CLIENTS/WEB_WORKSPACE.md`
- `Docs/API.md`
- `Docs/CHANNELS.md`
- `Docs/ASSISTANT_PERSONALIZATION.md`
- `Docs/JARVIS_USER_OVERVIEW.md` (баннер: origin overview, бренд LAVR)

`Docs/DECISIONS.md` и исторический CHANGELOG **не переписывались** — origin JARVIS.

Глубокая зачистка документации — **следующий этап**.

---

## F. Tests / checks

Paid AI, реальный Telegram, реальная почта, production integrations **не вызывались**.

| Команда | PASS / FAIL | Кратко |
| --- | --- | --- |
| `php artisan about` | PASS | Name LAVR, Laravel 13.30.1, PHP 8.5.0, env production, debug off, URL lavr.youngfashionshow.com |
| `php artisan migrate:status` | PASS | Все миграции Ran, включая `2026_09_09_091200_rename_default_assistant_name_to_lavr` |
| `php artisan route:list --path=lavr` | PASS | 72 маршрута на `/lavr`, имена `jarvis.*` |
| `php artisan route:list --path=jarvis` | PASS | Только GET redirect `/jarvis` и `/jarvis/{path}` |
| `php artisan route:list --name=register` | PASS (нет маршрутов) | Регистрации нет |
| `php artisan route:list --name=users` | PASS (нет маршрутов) | User CRUD routes нет |
| `php artisan config:cache` | PASS | |
| `php artisan route:cache` | PASS | Совместим с текущими routes |
| `php artisan view:cache` | PASS | |
| `php artisan test --compact tests/Feature/LavrSingleUserSurfaceTest.php` (+ route/capability unit) | PASS | 28 tests, 120 assertions |
| `php artisan test --compact tests/Feature/IdentityAuthorizationTest.php tests/Feature/Http/Controllers/Jarvis/JarvisWorkspaceControllerTest.php` | PASS | 9 tests |
| `php artisan test --compact tests/Unit/Watchers/ProactiveCheckIntentTest.php` | FAIL | 3 data sets (`wait_school`, `when_marco`, `each_mail`): `jarvisShouldMonitorMail` false. **Не регрессия Phase 1** — `ProactiveCheckIntent.php` не менялся |
| `php artisan test --compact tests/Unit/Watchers/WatcherDigestFormatterTest.php` | FAIL (error) | `Target class [config] does not exist` — plain PHPUnit TestCase без Laravel app. Pre-existing |
| `npm run build` | PASS | Vite 8.2.2, UsersPanel в бандл не входит |
| `vendor/bin/pint --dirty --format agent` | PASS | |
| `nginx -t` (как deploy) | FAIL | Permission denied на существующий SSL cert другого vhost |
| `https://lavr.youngfashionshow.com` | FAIL | Нет LAVR vhost; SSL на чужой CN |
| `http://lavr.youngfashionshow.com` | FAIL | Default nginx welcome, не приложение |
| Локальный login / `/lavr` / `/settings` / `/dashboard` через PHP 8.5 `artisan serve :8088` | PASS | Login 200 LAVR, без «Вход в Jarvis» и Register; POST login → `/lavr/chats/...`; Settings без UsersPanel / Add user; `/chat` и `/jarvis` 302 `/lavr`; guest `/lavr` → login; POST `/chat/chats` 405; POST `/settings/users` 404 |

Полный suite (~348 tests) в этой сессии целиком не гонялся повторно: в нём остаются как минимум 3 FAIL + 1 error выше, не связанные с multi-user removal.

---

## G. Git

- Remote **не** переключался на JARVIS.
- Push: `origin` = `https://github.com/Owiiiii1/lavr.git`.
- Сообщение commit и SHA — **ниже, после выполнения commit/push в этой же сессии.**

---

## H. Remaining risks / legacy / ручная проверка

### Сознательно leftover (internal)

- Namespace и контроллеры `Jarvis\*`, страницы `resources/js/Pages/Jarvis/`, layout `JarvisWorkspaceLayout`, voice `JarvisVoiceOrb`.
- Route names `jarvis.*`.
- Таблица `jarvis_notifications`, модели/enum с именем Jarvis.
- Deprecated `UserController` / `UserAdministrationService` / `ImpersonationService` без маршрутов.
- GET `/cabinet` редиректит в workspace; часть POST `cabinet/chats*` ещё существует как leftover cabinet API, не как второй personal UI.
- Роль Owner vs capabilities в коде остаётся (нужна для `/dashboard` admin). Product UI второго пользователя снят.
- Factory state `regularUser()` оставлен для тестов, не для продукта.
- SQLite вместо выделенной MySQL `lavr`.
- `WorkspaceUrl::isAllowlistedPath` ещё считает `/jarvis` и `/chat` допустимыми путями (для старых notification URL после redirect).

### Где ещё «Jarvis» внутри

Классы, миграции, config keys, tool internals, тесты с фикстурами «You are Jarvis.» / project name JARVIS, исторические Docs/DECISIONS.

### Следующий этап документации

Полная перепись `JARVIS_USER_OVERVIEW.md`, `IMPLEMENTATION_PLAN.md`, pitch, остальных доменных Docs под LAVR; вычистка origin multi-user абзацев, которые сейчас только помечены.

### Ручная проверка владельцем

1. **sudo** на этом сервере: PHP-FPM ≥ 8.4, nginx vhost, certbot, HTTP→HTTPS.
2. После этого — браузер: `https://lavr.youngfashionshow.com`, login, `/lavr`, Settings, отсутствие регистрации и Users.
3. Сменить bootstrap-пароль клиента; не хранить его в git.
4. Не копировать `.env` / БД / Telegram / Gmail credentials из production JARVIS (не делалось).
5. Проверить, что имя ассистента в профиле — LAVR (миграция трогала только точное `Jarvis`).
6. Pre-existing FAIL тестов watchers — отдельно, не Phase 1.

---

## Git (после commit)

| Поле | Значение |
| --- | --- |
| Репозиторий на сервере | `/var/www/lavr` |
| Branch | `main` |
| Remote `origin` | `https://github.com/Owiiiii1/lavr.git` (не JARVIS) |
| Implementation commit SHA | `f42cfe68e766861c3d9f4a12665cdd78b478f1dd` |
| Implementation commit message | `feat: convert LAVR to a single-client instance` |
| SHA-recording commit | `e838228` (`docs: record LAVR Phase 1 implementation commit SHA`) |
| Local `HEAD` before this note | `053ef4a` — `docs: record failed GitHub push for Phase 1` |
| Working tree before this note | clean |
| Ahead of `origin/main` | 3 commits (Phase 1 **есть локально**, на GitHub **нет**) |
| GitHub `origin/main` (`ls-remote`) | `682b8e1aac07737ea5302425153d14f0e4c5c6df` |

### Повторный push 2026-09-09 ~10:01 UTC+2 — FAIL

Команда: `GIT_TERMINAL_PROMPT=0 git push origin main`

Точная ошибка HTTPS:

```text
fatal: could not read Username for 'https://github.com': terminal prompts disabled
```

Ранее без `GIT_TERMINAL_PROMPT=0`:

```text
fatal: could not read Username for 'https://github.com': No such device or address
```

SSH (`ssh -i ~/.ssh/id_ed25519 -o BatchMode=yes -T git@github.com`):

```text
Offering public key: /home/deploy/.ssh/id_ed25519 ED25519 SHA256:aEV7/Lknjlwmi7mPi8XoxT9iNs7+kNB4avPnKZi9euo
git@github.com: Permission denied (publickey).
```

Проверено, что **не** помогает:

- `GH_TOKEN` / `GITHUB_TOKEN` в окружении — не заданы
- `gh auth status` — не залогинен ни на один GitHub host
- `~/.git-credentials`, `~/.netrc`, git credential helper — отсутствуют
- `git config --global` — пустой
- OAuth app keys в `.env` (`GITHUB_CLIENT_ID` / `GITHUB_CLIENT_SECRET`) — это не PAT и не дают `git push`
- таблица `integration_accounts` пустая — GitHub пользователя к инстансу не подключён

Публичный ключ сервера (можно добавить как **Deploy key** с write access на `Owiiiii1/lavr`):

```text
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKhZZoW3Ct/NBNH8mXG/45ZVzJdxg9qdf+pMJ/8VKmMq deploy@yfs-prod-yfs-ai
```

После добавления ключа или `gh auth login` / PAT с `repo` на этом сервере:

```bash
cd /var/www/lavr
git push origin main
```

Не пушить в `Owiiiii1/JARVIS`.

---

## I. Rebase onto fresh Jarvis upstream (2026-09-09)

Rebase: `main` onto `origin/main` (`7db12d6`).

Upstream commits included:

| SHA | Message |
| --- | --- |
| `40940f5` | `feat: deliver scheduled mail digests as spoken summaries` |
| `7db12d6` | `fix: size brief phrasing budget for reasoning models` |

Phase 1 replayed as `fd772d1` (`feat: convert LAVR to a single-client instance`) plus the three follow-up docs commits.

### Conflicts

**`Docs/CURRENT_STATE.md`**

- Kept LAVR identity: dedicated single-client instance, `/var/www/lavr`, `https://lavr.youngfashionshow.com`, `Owiiiii1/lavr`, no public multi-user product.
- Kept upstream Scheduled Reports facts: spoken mail digest, calendar DI fix, reasoning-model `phrasing_max_tokens` (1600), Owner live 08:30/09:00 notes.
- Product surfaces updated to canonical `/lavr`; `/jarvis` and `/chat` documented as GET redirects.

**`app/Services/Productivity/ProductivityBriefAiSynthesizer.php`**

- Kept upstream `systemPrompt($mode)`, `mail_groups_digest` spoken-digest prompt, completeness guard, and `config('productivity.briefs.phrasing_max_tokens', 1600)`.
- Replaced user-facing “Jarvis” in those prompts with **LAVR** only.

**`tests/Feature/Reports/ScheduledReportsTest.php`**

- Auto-merged, no conflict markers. Left as upstream + Phase 1 route/brand test adjustments already in the Phase 1 commit.

### Tests after rebase

Paid AI / live Telegram / live Gmail were not invoked. `php artisan config:clear` first (production `config:cache` made `phrasing_max_tokens` look like `0` in tests).

| Command | Result |
| --- | --- |
| `php artisan test --compact` LavrSingleUserSurface + BriefPhrasingBudget + ProductivityBriefPhrasing + ScheduledReportComposer + IdentityAuthorization | PASS 31 tests |
| `php artisan test --compact tests/Feature/Reports/ScheduledReportsTest.php` excluding `test_reminder_and_gmail_event_wording_stay_on_their_tools` | PASS 10 tests |
| `test_reminder_and_gmail_event_wording_stay_on_their_tools` | FAIL `assertTrue` on `CreateReminderTool` success — first assertion; `run_at_local` is `2026-09-09T09:00:00+02:00` (likely past relative to now). Not a rebase rollback of digest/reasoning logic. |
| `npm run build` | PASS |

### HEAD after rebase

- Onto: `7db12d6`
- Feature replay: `fd772d1`
- `main` tip after successful `git push origin main`: `37be325a1463ac012b003a62510c3878e2b23813` (`37be325`)

---

## J. Infrastructure (production URL) — 2026-09-09

Scoped to LAVR only. Other projects were not reconfigured.

### PHP-FPM

| Item | Value |
| --- | --- |
| Installed for LAVR | **php8.5-fpm 8.5.10** (ondrej/php PPA), socket `/run/php/php8.5-fpm.sock` |
| Unchanged | **php8.3-fpm 8.3.6** remains `active`, socket `/run/php/php8.3-fpm.sock` |
| Other vhosts | still `fastcgi_pass unix:/run/php/php8.3-fpm.sock` |
| System CLI `/usr/bin/php` | restored to **php8.3** after php8.5-cli briefly set auto mode to 8.5 |
| php-fpm.sock alternative | still points at **php8.3-fpm.sock** |
| php-common | upgraded 2:93ubuntu2 → ondrej 2:101 (shared helper; php8.3 packages not removed) |
| Removals | none |

LAVR artisan uses `/usr/bin/php8.5`. Other cron jobs keep `php` / `/usr/bin/php` = 8.3.

### MySQL (no passwords in this file)

| Item | Value |
| --- | --- |
| Database | `lavr` (created; was absent) |
| User | `lavr`@`localhost` and `lavr`@`127.0.0.1` |
| Grants | `ALL PRIVILEGES ON lavr.*` only |
| Other DBs | unchanged: `fashion_planner`, `jfs`, `yfs_ai`, `yfs_ai_sorter` |
| App `.env` | `DB_CONNECTION=mysql`, host `127.0.0.1`, database/user `lavr`; password only in `/var/www/lavr/.env` |
| Migrations | Ran on MySQL; SQLite file not imported |
| Users table | empty (clean instance; login page works; no client account seeded) |

### nginx / SSL

| Item | Value |
| --- | --- |
| vhost file | `/etc/nginx/sites-available/lavr.youngfashionshow.com` |
| symlink | `/etc/nginx/sites-enabled/lavr.youngfashionshow.com` (new only) |
| Other sites-enabled | unchanged (app, default, fashion-planner, yfs-ai, yfs-ai-sorter) |
| Document root | `/var/www/lavr/public` |
| PHP socket | `unix:/run/php/php8.5-fpm.sock` |
| `nginx -t` | PASS |
| Reload | `systemctl reload nginx` (not restart) |
| Cert | `certbot certonly --webroot` for **only** `lavr.youngfashionshow.com` |
| Cert path | `/etc/letsencrypt/live/lavr.youngfashionshow.com/` |
| Expiry | 2026-12-08 |
| Other certs | still present: app, ai, ai-sorting, planner |
| HTTP | `301` → `https://lavr.youngfashionshow.com/` |
| HTTPS | `200`, Inertia, cookie `lavr-session`, body contains `LAVR` |
| SSL CN | `CN=lavr.youngfashionshow.com` |

### Queue / scheduler

| Item | Value |
| --- | --- |
| systemd | `/etc/systemd/system/lavr-queue.service` → **active** (`php8.5 artisan queue:work`, cwd `/var/www/lavr`) |
| Other units | `yfs-voice-runtime` still active; no jarvis/yfs units edited |
| crontab | **appended** one LAVR line: `cd /var/www/lavr && /usr/bin/php8.5 artisan schedule:run` |
| Other crontab lines | jfs / yfs-ai / yfs-ai-sorter unchanged |

### Laravel production

`composer install --no-dev --optimize-autoloader`, `npm ci`, `npm run build`, `config/route/view:cache` on PHP 8.5. `.env` ownership `deploy:www-data` mode `640`. storage/bootstrap/cache `deploy:www-data` 775. No `chown -R /var/www`.

### Curl / tests

| Check | Result |
| --- | --- |
| `curl -I http://lavr.youngfashionshow.com` | **301** Location HTTPS |
| `curl -I https://lavr.youngfashionshow.com` | **200** LAVR |
| `php artisan about` | LAVR, Laravel 13.30.1, PHP 8.5.10, production, debug off |
| `migrate:status` | all Ran on MySQL |
| `route:list --path=lavr` | 72 routes |
| `LavrSingleUserSurfaceTest` | 4 PASS (login/register/redirects); 2 FAIL — no Owner row on empty MySQL (expected for clean instance) |
| `npm run build` | PASS |
| `https://app.youngfashionshow.com` | still 302 |
| `https://ai.youngfashionshow.com` | still 302 |
| php8.3-fpm | active |

### Other projects — not modified

- nginx vhost files for other domains: same mtimes
- SSL certs for other domains: still listed by certbot
- php8.3-fpm pool listen/user unchanged
- MySQL users/DBs of other apps unchanged
- `yfs-voice-runtime` still running
- existing crontab entries kept

