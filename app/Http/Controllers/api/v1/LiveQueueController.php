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
            "doctor_id" => "nullable|exists:users,id",
        ]);

        $this->authorizeBranchAccess($request->user(), $request->branch_id);

        $doctorId = $request->query('doctor_id');

        $queue = $this->liveQueueService->getQueueForBranch($request->branch_id, $doctorId);

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
            "doctor_id" => "nullable|exists:users,id",
        ]);

        $doctorId = $request->query('doctor_id');

        $queue = $this->liveQueueService->getQueueForBranch($request->branch_id, $doctorId);

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
        $user = $request->user();

        if (!$user->hasAnyRole(['receptionist', 'clinic_owner', 'tenant_admin', 'doctor'])) {
            abort(403, 'غير مصرح لك بإعادة ترتيب طابور الانتظار.');
        }

        $request->validate([
            'ordered_ids'   => 'required|array',
            'ordered_ids.*' => 'required|string',
            'branch_id'     => 'required|string',
        ]);

        $this->authorizeBranchAccess($user, $request->branch_id);

        // If user is a doctor without receptionist/owner role, verify they are only reordering their own queue
        if ($user->hasRole('doctor') && !$user->hasAnyRole(['receptionist', 'clinic_owner', 'tenant_admin'])) {
            $otherDoctorItems = LiveQueue::whereIn('id', $request->ordered_ids)
                ->where('doctor_id', '!=', $user->id)
                ->whereNotNull('doctor_id')
                ->exists();

            if ($otherDoctorItems) {
                abort(403, 'غير مصرح للطبيب بإعادة ترتيب طابور طبيب آخر.');
            }
        }

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
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'doctor_id' => 'nullable|exists:users,id',
            'room_name' => 'nullable|string|max:100',
        ]);

        $user = $request->user();
        $this->authorizeBranchAccess($user, $validated['branch_id']);

        if ($user->hasRole('doctor')) {
            // Doctors must NEVER call patients for another doctor. Strictly bind doctor_id = $user->id.
            $doctorId = $user->id;
        } elseif ($user->hasRole('clinic_owner') || $user->hasRole('tenant_admin')) {
            // Clinic owner may specify doctor_id or default to authenticated user
            $doctorId = $validated['doctor_id'] ?? $user->id;
            if (!empty($validated['doctor_id'])) {
                $doctorUser = \App\Models\User::where('id', $validated['doctor_id'])
                    ->where('tenant_id', $user->tenant_id)
                    ->first();

                if (!$doctorUser || !$doctorUser->branches()->where('branches.id', $validated['branch_id'])->exists()) {
                    abort(422, 'الطبيب المحدد لا ينتمي إلى هذا الفرع أو المركز الطبي.');
                }
            }
        } else {
            abort(403, 'غير مصرح لك باستدعاء المريض للكشف.');
        }

        $roomName = $validated['room_name'] ?? 'Examination Room';

        $nextPatient = $this->liveQueueService->callNextPatient($validated['branch_id'], $doctorId, $roomName);

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
            'patient.name'           => 'required_without:patient_id|string|max:255',
            'patient.phone'          => 'required_without:patient_id|string|max:255',
            'patient.age'            => 'nullable|integer|min:0|max:150',
            'patient.gender'         => 'nullable|in:male,female',
            'patient.medical_number' => 'nullable|string|max:100',
            'type'                   => 'nullable|string|in:check_up,consultation',
            'doctor_id'              => 'nullable|exists:users,id',
        ]);

        $this->authorizeBranchAccess($request->user(), $request->branch_id);

        $queueRecord = $appointmentService->checkInWalkIn(
            $request->only(['patient_id', 'patient', 'type', 'doctor_id']),
            $request->branch_id
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'تم تسجيل المريض المباشر ودخوله صالة الانتظار بنجاح',
            'data'    => new LiveQueueResource($queueRecord->load('patient'))
        ], 201);
    }
}
