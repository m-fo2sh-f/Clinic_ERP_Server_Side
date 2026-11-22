<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Clinic\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Clinic\Settings\UpdateVitalsConfigRequest;
use App\Http\Resources\Api\V1\Clinic\Settings\VitalsConfigResource;
use App\Models\Branch;
use App\Services\Clinic\Settings\ClinicSettingsResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VitalsConfigController extends Controller
{
    public function __construct(
        protected ClinicSettingsResolverService $resolverService
    ) {}

    /**
     * Display the resolved vitals configuration.
     */
    public function show(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request);
        $user = $request->user();
        $targetDoctorId = $request->query('doctor_id');

        $result = $this->resolverService->resolveVitalsConfig($branchId, $targetDoctorId, $user);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'data' => new VitalsConfigResource($result),
        ]);
    }

    /**
     * Update the vitals configuration.
     */
    public function update(UpdateVitalsConfigRequest $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request);
        $user = $request->user();
        $targetDoctorId = $request->input('doctor_id');

        $validated = $request->validated();
        $fields = $validated['fields'] ?? null;

        // If fields array not explicitly provided, synthesize from enabled_keys & custom_vitals
        if ($fields === null && isset($validated['enabled_keys'])) {
            $enabledKeys = $validated['enabled_keys'];
            $customVitals = $validated['custom_vitals'] ?? [];
            $defaultFields = ClinicSettingsResolverService::DEFAULT_VITALS_CONFIG['fields'];

            $fields = [];
            foreach ($defaultFields as $def) {
                $fields[] = array_merge($def, [
                    'is_enabled' => in_array($def['key'], $enabledKeys, true),
                    'show_in_prescription' => in_array($def['key'], $enabledKeys, true),
                ]);
            }
            foreach ($customVitals as $cv) {
                $fields[] = [
                    'key' => (string) ($cv['key'] ?? ''),
                    'label' => (string) ($cv['label'] ?? ''),
                    'label_en' => (string) ($cv['label_en'] ?? $cv['label'] ?? ''),
                    'unit' => (string) ($cv['unit'] ?? ''),
                    'type' => 'text',
                    'is_enabled' => in_array($cv['key'], $enabledKeys, true),
                    'show_in_prescription' => in_array($cv['key'], $enabledKeys, true),
                    'is_custom' => true,
                ];
            }
        }

        $configPayload = [
            'refraction_card_enabled' => (bool) ($validated['refraction_card_enabled'] ?? $validated['enable_refraction'] ?? false),
            'enable_refraction' => (bool) ($validated['enable_refraction'] ?? $validated['refraction_card_enabled'] ?? false),
            'enabled_keys' => $validated['enabled_keys'] ?? [],
            'custom_vitals' => $validated['custom_vitals'] ?? [],
            'fields' => $fields ?: ClinicSettingsResolverService::DEFAULT_VITALS_CONFIG['fields'],
        ];

        $result = $this->resolverService->saveVitalsConfig($branchId, $targetDoctorId, $user, $configPayload);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'message' => 'تم حفظ وتحديث إعدادات العلامات الحيوية بنجاح',
            'data' => new VitalsConfigResource($result),
        ]);
    }

    /**
     * Resolve the active branch ID and enforce authorization.
     */
    protected function resolveBranchId(Request $request): string
    {
        $branchId = $request->header('X-Branch-ID')
            ?: ($request->input('branch_id')
            ?: (app()->bound('active_branch_id') ? app('active_branch_id') : null));

        if (! $branchId) {
            $user = $request->user();
            if ($user && method_exists($user, 'branches')) {
                $branchId = $user->branches()->value('branches.id');
            }
        }

        if (! $branchId) {
            $branchId = Branch::value('id');
        }

        if (! $branchId) {
            abort(400, 'Branch context is required.');
        }

        $this->authorizeBranchAccess($request->user(), $branchId);

        return (string) $branchId;
    }
}
