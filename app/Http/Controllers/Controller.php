<?php

namespace App\Http\Controllers;
use App\Models\User;

abstract class Controller
{
    /**
     * Verify that the authenticated user has authorization to access the specified branch ID.
     */
    protected function authorizeBranchAccess(?User $user, string|int $branchId): void
    {
        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->hasRole('clinic_owner') || $user->hasRole('tenant_admin')) {
            return;
        }

        if (method_exists($user, 'branches') && !$user->branches()->where('branches.id', $branchId)->exists()) {
            abort(403, 'You are not authorized to access this branch.');
        }
    }
}

