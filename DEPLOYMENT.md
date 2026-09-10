# VODO — Production Deployment Checklist

A concrete, ordered checklist for taking this app from local dev to a real production
server. Pair with `.env.production.example` (copy it to `.env` and fill in every
`CHANGE-ME`).

## 1. Server requirements

- PHP 8.2+ with extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`,
  `ctype`, `json`, `bcmath`, `fileinfo`, `gd` or `imagick` (image previews), `zip`
  (download basket).
- MySQL/MariaDB 8+.
- Node 18+ and `npm` — needed once, at build time, to run `npm run build`. Not
  required at runtime (Vite's output is static JS/CSS committed to `public/build`).
- A process supervisor (`supervisor`, `systemd`, or your host's equivalent) for the
  queue worker — see §4.
- `cron` (or host equivalent) for the Laravel scheduler — see §5.

## 2. Before the first deploy

- [ ] Copy `.env.production.example` to `.env`, fill in **every** `CHANGE-ME`.
- [ ] `APP_DEBUG=false` and `APP_ENV=production` — confirm both. Leaving debug mode
      on in production is the single most common Laravel security mistake: every
      unhandled error becomes a public page showing stack traces, file paths, and
      config values.
- [ ] Generate a real `APP_KEY` (`php artisan key:generate --force`) — never reuse a
      key from a dev/staging environment. This key encrypts sessions and every
      `encrypted` cast column (AI provider keys, SMTP password, cold storage secret).
- [ ] `SESSION_SECURE_COOKIE=true` — **only** once HTTPS is actually serving the site
      (§6). Setting it before HTTPS is live locks out every user, since the browser
      will refuse to send the cookie back over plain HTTP.
- [ ] `QUEUE_CONNECTION=database` (not the dev default `sync`) — see §4 for why this
      matters and how to actually process the queue.
- [ ] Confirm `php.ini` has `upload_max_filesize` and `post_max_size` set to at least
      `512M` — the app already validates uploads up to 500MB in several places
      (document upload, new versions, reference/library files); a lower php.ini limit
      silently truncates or rejects those uploads before Laravel's own validation
      even runs. If served behind Nginx, also raise `client_max_body_size` to match.
- [ ] Point `DocumentVersionService`'s storage (the `documents` filesystem disk,
      `config/filesystems.php`) at a volume with real headroom, or switch it to S3 -
      it already supports that by configuration alone.

## 3. Deploy steps

```bash
composer install --no-dev --optimize-autoloader
npm install && npm run build
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Re-run `config:cache`/`route:cache`/`view:cache` after **every** deploy that changes
code or `.env` — a stale route/config cache serving old values is a common
post-deploy bug. `php artisan optimize:clear` un-does all of them if something looks
wrong.

**Do not** run `DemoUsersSeeder` or `DatabaseSeeder`'s demo data against production -
they create real login accounts with a shared, publicly-documented password
(`welcome`, see `database/seeders/DemoUsersSeeder.php`). The Himalaya and Scientific
Publications user seeders also use `welcome` (with a forced password change on first
login) - swap those back to an unusable random hash for production. They're for
local/demo/UAT only. The `admin@globalspace.in` / `welcome` seeded admin account
should have its password changed immediately after the first real login.

## 4. Queue worker (required)

Every notification (in-app + email) and the AI-triggered background work run through
Laravel's queue. With `QUEUE_CONNECTION=sync` (the local-dev default) these run
synchronously inside the HTTP request — acceptable for local testing, but in
production it means every action that notifies stakeholders (a decision, a comment, a
new version, cold storage retrieval, content edits) blocks the user's request on
however long mail delivery takes, and a slow/down SMTP server makes those requests
hang or fail outright.

Switch to `QUEUE_CONNECTION=database` (already migrated — see `jobs`/`failed_jobs`
tables) and run a supervised worker:

```ini
; /etc/supervisor/conf.d/vodo-queue.conf
[program:vodo-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/vodo/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
numprocs=2
user=www-data
stdout_logfile=/path/to/vodo/storage/logs/queue.log
stopwaitsecs=3600
```

Check `php artisan queue:failed` periodically (or wire up `queue:failed` monitoring) -
a job that fails 3 times lands in `failed_jobs` rather than retrying forever.

## 5. Scheduler (required)

Several features are cron-driven (see `routes/console.php`): lifecycle/expiry flags,
overdue-approval reminders, archiving policy, cold storage migration, and cold
storage retrieval processing. Add the single standard Laravel cron entry:

```
* * * * * cd /path/to/vodo && php artisan schedule:run >> /dev/null 2>&1
```

Laravel's own scheduler then fires each job at the interval already configured in
`routes/console.php` - no per-job cron entries needed.

## 6. Web server / HTTPS

- Document root is `public/`, **not** the repo root — never point Nginx/Apache at the
  project root itself, which would expose `.env`, `app/`, `storage/`, etc. directly.
- Terminate TLS at the load balancer/reverse proxy or directly in Nginx/Apache; once
  HTTPS is live, set `SESSION_SECURE_COOKIE=true` (§2) and consider adding HSTS at
  the web server level.
- If running behind a reverse proxy/load balancer, configure Laravel's
  `TrustProxies` (`bootstrap/app.php` / `config/trustedproxy` depending on setup) so
  `request()->ip()` and HTTPS detection reflect the real client, not the proxy.

## 7. Health check & monitoring

- `GET /up` is Laravel's built-in health check (already wired in `bootstrap/app.php`)
  - point your uptime monitor / load balancer health check at it.
- `storage/logs/` (daily-rotating once `LOG_STACK=daily` is set, §2) is the first
  place to look for application errors. Consider shipping it to an external log
  aggregator or error tracker (Sentry, Flare, etc.) for anything beyond a
  single-server deployment - not configured here, since it depends on which service
  you use.

## 8. Backups

- **Database**: a nightly `mysqldump` (or your host's managed-DB snapshot feature) is
  the minimum. Given this is an audit-trail-driven approval system, treat DB backups
  as compliance-relevant, not just disaster recovery — verify restores periodically,
  not just that the dump file exists.
- **File storage**: the `documents` disk (uploaded files, versions, reference
  attachments) needs its own backup independent of the database — a DB restore alone
  won't bring back the actual files. If using S3/cold storage, that tier typically
  has its own durability guarantees, but the primary `documents` disk (local by
  default) does not.

## 9. Post-deploy smoke test

- [ ] Log in with a real (non-demo) account.
- [ ] Upload a document, submit it into a workflow, record a decision — confirm an
      email notification actually arrives (proves mail config + queue worker are both
      live).
- [ ] Check `/up` returns 200.
- [ ] Check `php artisan queue:work database --stop-when-empty` (or the supervisor
      log) shows jobs completing, not piling up in `failed_jobs`.
- [ ] Confirm `APP_DEBUG` is really `false` — trigger a 404 and confirm it shows the
      generic error page, not a stack trace.
