<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformImpersonationController extends Controller
{
    public function __construct(
        protected PlatformImpersonationService $impersonationService
    ) {}

    /**
     * POST /api/v1/platform/tenants/{id}/impersonate
     */
    public function impersonate(Request $request, string $id): JsonResponse
    {
        $result = $this->impersonationService->impersonateClinicOwner($id, $request->user(), $request);

        return response()->json([
            'status'  => 'success',
            'message' => 'تم إنشاء جلسة الدخول المؤقتة بنجاح (صالحة لمدة 15 دقيقة)',
            'data'    => $result,
        ]);
    }
}
