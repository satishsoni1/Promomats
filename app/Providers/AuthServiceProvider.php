<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentStageAssignee;
use App\Models\WorkflowTemplate;
use App\Policies\ApprovalTaskPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\WorkflowPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Explicit rather than relying on Laravel's convention-based auto-discovery
     * (App\Models\X -> App\Policies\XPolicy), since two of these deliberately
     * don't follow it: WorkflowTemplate -> WorkflowPolicy and
     * DocumentStageAssignee -> ApprovalTaskPolicy, named after the domain
     * concept (Workflow, Approval Task) rather than the Eloquent model.
     */
    protected $policies = [
        Document::class => DocumentPolicy::class,
        WorkflowTemplate::class => WorkflowPolicy::class,
        DocumentStageAssignee::class => ApprovalTaskPolicy::class,
        AuditLog::class => AuditLogPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        // Global, unscoped admin - system settings, roles, brands, claims, etc.
        Gate::define('access-admin', function ($user) {
            return $user->isGlobalAdmin();
        });

        // Can open the /admin area: a global admin, or a department admin (whose
        // controllers then scope everything to $user->adminDepartmentScope()).
        Gate::define('access-admin-area', function ($user) {
            return $user->canAdminister();
        });
    }
}
