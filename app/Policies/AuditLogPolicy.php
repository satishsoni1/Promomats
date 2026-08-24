<?php

namespace App\Policies;

use App\Models\User;

class AuditLogPolicy
{
    /**
     * The audit trail is compliance/investigation data, not a general user
     * feature - restricted to admins, same bar as the rest of /admin.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('access-admin');
    }
}
