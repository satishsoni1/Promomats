<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowTemplate;

class WorkflowPolicy
{
    /**
     * Any authenticated user can see the list of active templates (it's a
     * dropdown on the document create form) - only building/editing one is
     * privileged.
     */
    public function view(User $user, WorkflowTemplate $workflow): bool
    {
        return true;
    }

    /**
     * Create, edit stages/transitions, publish. Matches the `can:access-admin`
     * gate already wrapping every /admin/workflows/* route in routes/web.php -
     * defined here as well so the same rule is enforceable from anywhere a
     * WorkflowTemplate is touched, not just inside that route group.
     */
    public function manage(User $user, ?WorkflowTemplate $workflow = null): bool
    {
        return $user->can('access-admin');
    }
}
