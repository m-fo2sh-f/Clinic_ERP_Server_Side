<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LiveQueueStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Services\LiveQueueService;
use App\Models\LiveQueue;
use App\Http\Resources\LiveQueue\LiveQueueResource;
use App\Http\Resources\LiveQueue\PublicLiveQueueResource;

class LiveQueueController extends Controller
{
    protected $liveQueueService;

    public function __construct(LiveQueueService $liveQueueService)
    {
        $this->liveQueueService = $liveQueueService;
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            "branch_id" => "required|exists:branches,id",
        ]);

        $this->authorizeBranchAccess($request->user(), $request->branch_id);

        $queue = $this->liveQueueService->getQueueForBranch($request->branch_id);

        return response()->json([
            'status' => 'success',
            'data'   => LiveQueueResource::collection($queue)
        ], 200);
    }

    /**
     * Unauthenticated public endpoint for TV Waiting Room displays.
     */
    public function publicIndex(Request $request): JsonResponse
    {
        $request->validate([
            "branch_id" => "required|exists:branches,id",
        ]);

        $queue = $this->liveQueueService->getQueueForBranch($request->branch_id);

        return response()->json([
            'status' => 'success',
            'data'   => PublicLiveQueueResource::collection($queue)
        ], 200);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            "status" => ["required", Rule::enum(LiveQueueStatus::class)],
        ]);

        $queueItem = LiveQueue::findOrFail($id);
        $this->authorizeBranchAccess($request->user(), $queueItem->branch_id);

        $queue = $this->liveQueueService->updateStatus($id, $request->status);

        return response()->json([
            'status' => 'success',
            'data'   => new LiveQueueResource($queue)
        ], 200);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $queueItem = LiveQueue::findOrFail($id);
        $this->authorizeBranchAccess($request->user(), $queueItem->branch_id);

        $this->liveQueueService->destroyQueueItem($id);

        return response()->json([
            'status'  => 'success',
            'message' => 'Patient removed from waiting queue successfully',
        ], 200);
    }

    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'ordered_ids'   => 'required|array',
            'ordered_ids.*' => 'required|string',
            'branch_id'     => 'required|string',
        ]);

        $this->authorizeBranchAccess($request->user(), $request->branch_id);

        $this->liveQueueService->reorderQueue(
            $request->ordered_ids,
            $request->branch_id
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Queue reordered successfully',
        ], 200);
    }

    /**
     * Call the next waiting patient for examination.
     */
    public function nextPatient(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
        ]);

        $this->authorizeBranchAccess($request->user(), $request->branch_id);

        $nextPatient = $this->liveQueueService->callNextPatient($request->branch_id);

        if (!$nextPatient) {
            return response()->json([
                'status'  => 'success',
                'message' => 'No waiting patients in queue',
                'data'    => null,
            ], 200);
        }

        return response()->json([
            'status' => 'success',
            'data'   => new LiveQueueResource($nextPatient),
        ], 200);
    }

    /**
     * Direct Walk-In check-in (creates Appointment SSOT + LiveQueue record).
     */
    public function checkInWalkIn(Request $request, \App\Services\AppointmentService $appointmentService): JsonResponse
    {
        $request->validate([
            'branch_id'      => 'required|exists:branches,id',
            'patient_id'     => 'nullable|exists:patients,id',
            'patient'        => 'required_without:patient_id|array',
            'patient.name'   => 'required_without:patient_id|string|max:255',
            'patient.phone'  => 'required_without:patient_id|string|max:255',
            'type'           => 'nullable|string|in:check_up,consultation',
        ]);

        $this->authorizeBranchAccess($request->user(), $request->branch_id);

        $queueRecord = $appointmentService->checkInWalkIn(
            $request->only(['patient_id', 'patient', 'type']),
            $request->branch_id
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'تم تسجيل المريض المباشر ودخوله صالة الانتظار بنجاح',
            'data'    => new LiveQueueResource($queueRecord->load('patient'))
        ], 201);
    }
}
