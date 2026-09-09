<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Platform\UpdateTenantBranchRequest;
use App\Services\Platform\PlatformTenantBranchService;
use Illuminate\Http\JsonResponse;

class PlatformTenantBranchController extends Controller
{
    public function __construct(
        protected PlatformTenantBranchService $branchService
    ) {}

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
