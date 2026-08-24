# Document Approval & Version Control System (Laravel)

A configurable, multi-role document approval workflow system — built so that the
approval chain in your PromoMats diagram (Content Manager → Regulatory L1/L2 →
Legal L1/L2 → Document Owner → TM/AGM Marketing → R&D/Chairperson's Office →
Design/Distribution, with A / AwC / NA decisions and revision loops) is **data you
configure in the admin UI**, not code. You can define any number of workflows this
way — Pharma Workflow 1, 2, 3, or something completely different next year —
without touching PHP.

## What's included

| Requirement | How it's implemented |
|---|---|
| Upload any file type/size | `DocumentVersionService` stores raw uploads on a configurable disk (local or S3); no MIME restriction, size bounded only by PHP/infra config |
| Admin-defined approval flow | `WorkflowTemplate` → `WorkflowStage` → `WorkflowStageApprover` → `WorkflowTransition` — fully relational, editable from Admin > Workflows |
| Multi-user approve/reject per flow | `WorkflowEngine::recordDecision()` — supports both "any one approver" and "all approvers required" per stage |
| Rules-driven behavior (A / AwC / NA) | `workflow_transitions` table: per stage, per decision, define next stage / return-to-stage (revision loop) / terminate / complete |
| Notifications on every action | `DocumentActionNotification` (database + mail channel), fired on stage entry, every decision, and workflow completion |
| Admin creates users & assigns roles | `Admin\UserController`, `Admin\RoleController` — custom lightweight RBAC (no external package dependency) |
| Login | Pair with Laravel Breeze/Fortify (`php artisan breeze:install`) — this scaffold assumes standard `auth` middleware |
| Version control | `document_versions` table — every upload is a new immutable version with checksum, uploader, change notes; `is_current` flag tracks latest |
| Aging / expiry / start-date flags | `documents.start_date`, `expiry_date`, `aging_warning_days`, `is_expired`, `is_aging_flagged` + daily scheduled command `documents:flag-lifecycle` |

## How your diagram maps to this system

Your PDF shows three named workflows built from a shared vocabulary of roles
(Content Manager, Regulatory L1/L2, Legal L1/L2, Document Owner, TM/AGM Marketing,
R&D, Chairperson's Office, Design Internal/External/Agency) and three decision
codes:

- **A** = Approved → move to next stage
- **AwC** = Approved with Changes → back to Content Manager for revision, then resume
- **NA** = Not Approved → back to Content Manager, or terminate if rejected at the first gate

`database/seeders/PharmaWorkflowSeeder.php` reconstructs this as an actual seeded
workflow ("Pharma Workflow 1") so you can see the pattern end-to-end, then edit
stage order, approvers, and transition rules from the Admin UI to match your exact
SOP for Workflow 2 and Workflow 3 (I built one fully as a working example rather
than guessing exact stage order for all three from the diagram — the structure
supports as many templates as you need).

## Setup

```bash
composer create-project laravel/laravel promomats-approval-system
cd promomats-approval-system
composer require laravel/breeze --dev
php artisan breeze:install blade
```

Then copy this scaffold's `app/`, `database/`, `routes/` contents into place
(merge rather than overwrite Breeze's auth scaffolding), and:

```bash
# add the 'documents' disk from config/filesystems-documents-disk-snippet.php
# into config/filesystems.php's 'disks' array

php artisan migrate
php artisan db:seed
php artisan storage:link
```

Default admin login after seeding: `admin@example.com` / `ChangeMe123!`
— **change this immediately.**

For large-file uploads (100MB+), also raise in `php.ini`:
```ini
upload_max_filesize = 500M
post_max_size = 520M
max_execution_time = 300
```

### Docker (development)

```bash
cp .env.example .env   # docker-compose.yml reads this for DB credentials, APP_KEY, etc.
docker compose up -d --build
docker compose exec app php artisan key:generate --force   # first run only
docker compose exec app php artisan db:seed --force
```

Services: `app` (PHP-FPM), `nginx` (:8080 by default, set `APP_PORT` to change),
`mysql`, `redis`, plus dedicated `queue` and `scheduler` containers so a slow job
or the SLA/archiving cron never blocks a web request. The `app` container
migrates automatically on start; `queue`/`scheduler` don't (avoids every
container racing to migrate at once). Not intended for production as-is — see
the comment at the top of `docker-compose.yml`.

### Scheduler (for aging/expiry flags)

Ensure your cron runs Laravel's scheduler:
```
* * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
```
This triggers `documents:flag-lifecycle` daily at 06:00, which flags newly
expired/aging documents and notifies owners.

### Queue worker (for notifications)

`DocumentActionNotification` implements `ShouldQueue`, so run a queue worker in
production so notification delivery doesn't block requests:
```bash
php artisan queue:work
```

## Core data flow

1. **Upload**: `DocumentController::store()` creates a `Document` (draft) + first
   `DocumentVersion` via `DocumentVersionService`.
2. **Submit for review**: `DocumentController::submit()` calls
   `WorkflowEngine::start()`, which enters the template's first stage, resolves
   role → concrete users via `WorkflowStageApprover`, creates
   `DocumentStageAssignee` rows (the "pending approval" queue), and notifies them.
3. **Approve/Reject**: `DocumentApprovalController::act()` calls
   `WorkflowEngine::recordDecision()`. This logs to `document_approval_actions`
   (permanent audit trail), then resolves the stage via the matching
   `WorkflowTransition` row:
   - `next_stage` → move forward
   - `return_to_stage` → revision loop (e.g. back to Content Manager); the engine
     remembers `resume_at_stage_id` so re-submission continues from where it left off
   - `terminate_rejected` → document marked rejected, workflow ends
   - `complete_approved` → document marked approved / approved for distribution
4. **Revision re-submit**: after an AwC/NA loop, the owner uploads a new version;
   `WorkflowEngine::resumeAfterRevision()` picks the workflow back up at the
   remembered stage rather than restarting from scratch.
5. **Notifications**: fired at every stage entry, every decision, and on
   completion — to the document owner, initiator, watchers, and current pending
   approvers.

## Extending

- **Parallel approvals**: already supported via `approval_mode = all_required`
  on a stage (e.g. Regulatory L1 + Legal L1 both must sign off before moving on)
  — just attach multiple `WorkflowStageApprover` rows to one stage.
- **SLA/escalation**: `workflow_stages.sla_hours` is scaffolded but not yet wired
  to an escalation action — add a scheduled command that checks
  `document_stage_assignees` older than the SLA and either reminds or reassigns.
- **E-signatures / comments-as-mandatory**: already enforced in
  `DocumentApprovalController::act()` — comments are required for anything other
  than a clean "approved".
- **Reports**: `document_approval_actions` is a complete, queryable audit trail —
  build report views/exports (e.g. average time-in-stage, rejection rate per
  role) directly off it.

## Not included (by design, to keep this a clean starting scaffold)

- Frontend Blade views — controllers/routes return view names; build these to
  match your existing VODO/Zorvia UI conventions (you already have an established
  Blade + sticky-header pattern from your other modules — reuse it here).
- Full RBAC package (e.g. spatie/laravel-permission) — I used a lightweight
  custom roles/permissions schema so this scaffold has zero Composer package
  dependencies beyond Laravel itself. Swap in spatie's package if you'd prefer
  its more advanced permission caching/API.
