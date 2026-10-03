<?php

namespace App\Http\Controllers\Api\V1\Clinic;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BranchController extends Controller
{
    /**
     * Get active doctors assigned to a specific branch.
     */
    public function doctors(Request $request, string $branchId): JsonResponse
    {
        $this->authorizeBranchAccess($request->user(), $branchId);

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
