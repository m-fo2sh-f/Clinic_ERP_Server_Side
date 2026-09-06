<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Platform\ToggleTenantStatusRequest;
use App\Http\Resources\Platform\PlatformTenantDetailResource;
use App\Http\Resources\Platform\PlatformTenantListResource;
use App\Http\Resources\Platform\PlatformTenantUserResource;
use App\Services\Platform\PlatformTenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformTenantController extends Controller
{
    public function __construct(
        protected PlatformTenantService $tenantService
    ) {}

    /**
     * GET /api/v1/platform/tenants
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
        ];

        $perPage = (int) $request->query('per_page', 15);
        $tenants = $this->tenantService->getTenants($filters, $perPage);

        return response()->json([
            'status' => 'success',
            'data'   => PlatformTenantListResource::collection($tenants),
            'meta'   => [
                'current_page' => $tenants->currentPage(),
                'last_page'    => $tenants->lastPage(),
                'per_page'     => $tenants->perPage(),
                'total'        => $tenants->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/platform/tenants/{id}
     */
    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenantService->getTenantDetails($id);

        return response()->json([
            'status' => 'success',
            'data'   => new PlatformTenantDetailResource($tenant),
        ]);
    }

    /**
     * GET /api/v1/platform/tenants/{id}/users
     */
    public function users(Request $request, string $id): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);
        $users = $this->tenantService->getTenantUsers($id, $perPage);

        return response()->json([
            'status' => 'success',
            'data'   => PlatformTenantUserResource::collection($users),
            'meta'   => [
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'per_page'     => $users->perPage(),
                'total'        => $users->total(),
            ],
        ]);
    }

    /**
     * POST /api/v1/platform/tenants/{id}/status
     */
    public function toggleStatus(ToggleTenantStatusRequest $request, string $id): JsonResponse
    {
        $isActive = $request->boolean('is_active');
        $tenant = $this->tenantService->toggleStatus($id, $isActive, $request->user(), $request);

        return response()->json([
            'status'  => 'success',
            'message' => $isActive ? 'تم تفعيل العيادة بنجاح' : 'تم إيقاف العيادة بنجاح',
            'data'    => new PlatformTenantDetailResource($tenant),
        ]);
    }
}
