<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Platform\ResetTenantUserPasswordRequest;
use App\Http\Requests\Api\V1\Platform\UpdateTenantUserRequest;
use App\Http\Resources\Api\V1\Platform\PlatformTenantUserResource;
use App\Services\Platform\PlatformTenantStaffService;
use Illuminate\Http\JsonResponse;

class PlatformTenantStaffController extends Controller
{
    public function __construct(
        protected PlatformTenantStaffService $staffService
    ) {}

    /**
     * PUT /api/v1/platform/tenants/{tenantId}/users/{userId}
     */
    public function update(UpdateTenantUserRequest $request, string $tenantId, string $userId): JsonResponse
    {
        $user = $this->staffService->updateUser(
            $tenantId,
            $userId,
            $request->validated(),
            $request->user(),
            $request
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'تم تحديث بيانات المستخدم وأدواره بنجاح',
            'data'    => new PlatformTenantUserResource($user),
        ]);
    }

    /**
     * POST /api/v1/platform/tenants/{tenantId}/users/{userId}/reset-password
     */
    public function resetPassword(ResetTenantUserPasswordRequest $request, string $tenantId, string $userId): JsonResponse
    {
        $this->staffService->resetPassword(
            $tenantId,
            $userId,
            $request->validated('password'),
            $request->user(),
            $request
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'تم إعادة تعيين كلمة المرور بنجاح وإنهاء كافة الجلسات الحالية للمستخدم.',
        ]);
    }
}
