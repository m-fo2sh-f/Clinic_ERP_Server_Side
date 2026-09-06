<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformMetricsController extends Controller
{
    public function __construct(
        protected PlatformMetricsService $metricsService
    ) {}

    /**
     * GET /api/v1/platform/metrics
     */
    public function index(Request $request): JsonResponse
    {
        $metrics = $this->metricsService->getMetrics();

        return response()->json([
            'status' => 'success',
            'data'   => $metrics,
        ]);
    }
}
