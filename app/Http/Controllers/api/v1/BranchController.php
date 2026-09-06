<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class BranchController extends Controller
{
    /**
     * Get active doctors assigned to a specific branch.
     */
    public function doctors(string $branchId): JsonResponse
    {
        $doctors = User::whereHas('roles', function ($q) {
                $q->whereIn('name', ['doctor', 'clinic_owner']);
            })
            ->whereHas('branches', function ($q) use ($branchId) {
                $q->where('branches.id', $branchId);
            })
            ->select(['users.id', 'users.name', 'users.email'])
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $doctors,
        ]);
    }
}
