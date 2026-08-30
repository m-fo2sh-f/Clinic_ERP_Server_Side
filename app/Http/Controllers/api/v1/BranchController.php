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
        $doctors = User::role(['doctor', 'clinic_owner'])
            ->where(function ($query) use ($branchId) {
                $query->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId);
                })
                ->orDoesntHave('branches');
            })
            ->select(['users.id', 'users.name', 'users.email'])
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $doctors,
        ]);
    }
}
