<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Platform\StoreTenantBranchRequest;
use App\Http\Requests\Api\V1\Platform\UpdateTenantBranchRequest;
use App\Services\Platform\PlatformTenantBranchService;
use Illuminate\Http\JsonResponse;

class PlatformTenantBranchController extends Controller
{
    public function __construct(
        protected PlatformTenantBranchService $branchService
    ) {}

    /**
     * POST /api/v1/platform/tenants/{tenantId}/branches
     */
    public function store(StoreTenantBranchRequest $request, string $tenantId): JsonResponse
    {
        $branch = $this->branchService->createBranch(
            $tenantId,
            $request->validated(),
            $request->user(),
            $request
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'تم إنشاء الفرع بنجاح',
            'data'    => [
                'id'        => $branch->id,
                'name'      => $branch->name,
                'address'   => $branch->address,
                'phone'     => $branch->phone,
                'is_active' => (bool) $branch->is_active,
            ],
        ], 201);
    }

    /**
     * PUT /api/v1/platform/tenants/{tenantId}/branches/{branchId}
     */
    public function update(UpdateTenantBranchRequest $request, string $tenantId, string $branchId): JsonResponse
    {
        $branch = $this->branchService->updateBranch(
            $tenantId,
            $branchId,
            $request->validated(),
            $request->user(),
            $request
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'تم تحديث بيانات الفرع بنجاح',
            'data'    => [
                'id'        => $branch->id,
                'name'      => $branch->name,
                'address'   => $branch->address,
                'phone'     => $branch->phone,
                'is_active' => (bool) $branch->is_active,
            ],
        ]);
    }
}
