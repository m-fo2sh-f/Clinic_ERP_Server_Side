<?php

namespace App\Http\Controllers\Api\V1\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Encounter\CompleteEncounterRequest;
use App\Http\Requests\Api\V1\Encounter\QuickStartEncounterRequest;
use App\Http\Requests\Api\V1\Encounter\SaveDraftEncounterRequest;
use App\Http\Resources\Api\V1\Encounter\EncounterResource;
use App\Models\Encounter;
use App\Services\Clinic\EncounterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EncounterController extends Controller
{
    protected EncounterService $encounterService;

    public function __construct(EncounterService $encounterService)
    {
        $this->encounterService = $encounterService;
    }

    /**
     * Start a walk-in encounter or resume draft.
     */
    public function quickStart(QuickStartEncounterRequest $request): JsonResponse
    {
        $doctorId = (int) $request->user()->id;
        $branchId = (string) $request->input('branch_id');

        $this->authorizeBranchAccess($request->user(), $branchId);

        try {
            $encounter = $this->encounterService->startWalkIn($request->validated(), $doctorId, $branchId);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم بدء جلسة الكشف بنجاح',
                'data'    => new EncounterResource($encounter),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() === 409 ? 409 : 422;
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $code);
        }
    }

    /**
     * Auto-save draft encounter (non-blocking).
     */
    public function saveDraft(SaveDraftEncounterRequest $request, string $id): JsonResponse
    {
        $doctorId = (int) $request->user()->id;

        $encounter = Encounter::findOrFail($id);
        $this->authorizeBranchAccess($request->user(), $encounter->branch_id);

        try {
            $encounter = $this->encounterService->saveDraft($id, $request->validated(), $doctorId);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم حفظ المسودة بنجاح',
                'data'    => new EncounterResource($encounter),
            ], 200);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() === 409 ? 409 : 422;
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $code);
        }
    }

    /**
     * Complete encounter with clinical wrap-up, medications, and checkout.
     */
    public function complete(CompleteEncounterRequest $request, string $id): JsonResponse
    {
        $doctorId = (int) $request->user()->id;

        $encounter = Encounter::findOrFail($id);
        $this->authorizeBranchAccess($request->user(), $encounter->branch_id);

        try {
            $result = $this->encounterService->completeEncounter($id, $request->validated(), $doctorId);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم إنهاء الكشف وإصدار الروشتة والفاتورة بنجاح',
                'data'    => [
                    'encounter'    => new EncounterResource($result['encounter']),
                    'prescription' => $result['prescription'] ? [
                        'id'                => $result['prescription']->id,
                        'prescription_code' => $result['prescription']->prescription_code,
                        'prescription_date' => $result['prescription']->prescription_date,
                        'general_advice'    => $result['prescription']->general_advice,
                        'follow_up_date'    => $result['prescription']->follow_up_date,
                        'items'             => $result['prescription']->items,
                    ] : null,
                    'invoice'      => $result['invoice'] ? [
                        'id'             => $result['invoice']->id,
                        'invoice_number' => $result['invoice']->invoice_number,
                        'subtotal'       => (float) $result['invoice']->subtotal,
                        'discount'       => (float) $result['invoice']->discount,
                        'total'          => (float) $result['invoice']->total,
                        'payment_status' => $result['invoice']->payment_status?->value ?? $result['invoice']->payment_status,
                        'paid_at'        => $result['invoice']->paid_at,
                        'items'          => $result['invoice']->items,
                        'payments'       => $result['invoice']->payments,
                    ] : null,
                ],
            ], 200);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() === 409 ? 409 : 422;
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $code);
        }
    }

    /**
     * Get today's encounters summary for the branch/doctor.
     */
    public function todaySummary(Request $request): JsonResponse
    {
        $branchId = (string) $request->query('branch_id');
        if (!$branchId) {
            return response()->json(['status' => 'error', 'message' => 'يجب تحديد الفرع'], 422);
        }

        $this->authorizeBranchAccess($request->user(), $branchId);

        $doctorId = $request->user()->hasRole('doctor') ? (int)$request->user()->id : null;
        $summary = $this->encounterService->getTodaySummary($branchId, $doctorId);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'total_encounters'  => $summary['total_encounters'],
                'completed_count'   => $summary['completed_count'],
                'in_progress_count' => $summary['in_progress_count'],
                'total_revenue'     => $summary['total_revenue'],
                'encounters'        => EncounterResource::collection($summary['encounters']),
            ],
        ], 200);
    }

    /**
     * Show single encounter details.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $encounter = Encounter::with(['patient', 'branch', 'doctor', 'prescription.items', 'invoice.items', 'invoice.payments'])
            ->findOrFail($id);

        $this->authorizeBranchAccess($request->user(), $encounter->branch_id);

        return response()->json([
            'status' => 'success',
            'data'   => new EncounterResource($encounter),
        ], 200);
    }

    /**
     * Get active in_progress encounter for the authenticated doctor (session recovery).
     * GET /api/v1/encounters/active
     */
    public function active(Request $request): JsonResponse
    {
        $branchId = (string) $request->query('branch_id');
        if (!$branchId) {
            $branchId = (string) $request->user()->branches()->first()?->id;
        }

        if (!$branchId) {
            return response()->json(['status' => 'error', 'message' => 'يجب تحديد الفرع'], 422);
        }

        $this->authorizeBranchAccess($request->user(), $branchId);

        $doctorId = (int) $request->user()->id;
        $encounter = $this->encounterService->getActiveEncounterForDoctor($doctorId, $branchId);

        return response()->json([
            'status' => 'success',
            'data'   => $encounter ? new EncounterResource($encounter) : null,
        ], 200);
    }

    /**
     * Abandon an in-progress encounter (Doctor Abandon / Patient Absent).
     * POST /api/v1/encounters/{id}/abandon
     */
    public function abandon(Request $request, string $id): JsonResponse
    {
        $doctorId = (int) $request->user()->id;

        $encounter = Encounter::findOrFail($id);
        $this->authorizeBranchAccess($request->user(), $encounter->branch_id);

        try {
            $abandoned = $this->encounterService->abandonEncounter($id, $doctorId);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم إلغاء جلسة الكشف بنجاح',
                'data'    => new EncounterResource($abandoned),
            ], 200);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() === 409 ? 409 : ($e->getCode() === 403 ? 403 : 422);
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $code);
        }
    }
}
