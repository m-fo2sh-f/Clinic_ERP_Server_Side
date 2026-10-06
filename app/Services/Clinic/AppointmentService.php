<?php

namespace App\Services\Clinic;

use App\Enums\AppointmentStatus;
use App\Helpers\ShiftHelper;
use App\Models\Appointment;
use App\Models\LiveQueue;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;

class AppointmentService
{
    private LiveQueueService $liveQueueService;

    private PatientService $patientService;

    private BillingService $billingService;

    public function __construct(
        LiveQueueService $liveQueueService,
        PatientService $patientService,
        BillingService $billingService
    ) {
        $this->liveQueueService = $liveQueueService;
        $this->patientService = $patientService;
        $this->billingService = $billingService;
    }

    public function getAllAppointmentsForBranch(int|string $branchId, ?string $date = null, int|string|null $doctorId = null)
    {
        [$startTime, $endTime] = ShiftHelper::getShiftWindow($date);

        $query = Appointment::where('branch_id', $branchId)
            ->with(['patient', 'doctor', 'branch'])
            ->whereBetween('appointment_time', [$startTime, $endTime]);

        if (! empty($doctorId)) {
            $query->where(function ($q) use ($doctorId) {
                $q->where('doctor_id', $doctorId)->orWhereNull('doctor_id');
            });
        }

        return $query->orderBy('appointment_time', 'asc')->get();
    }

    public function createAppointment(array $data): Appointment
    {
        return DB::transaction(function () use ($data) {
            $doctorId = $data['doctor_id'] ?? null;
            $branchId = $data['branch_id'];
            $appointmentTime = $data['appointment_time'];

            if (! empty($doctorId)) {
                $hasConflict = Appointment::where('branch_id', $branchId)
                    ->where('doctor_id', $doctorId)
                    ->where('appointment_time', $appointmentTime)
                    ->whereNotIn('status', [AppointmentStatus::CANCELLED->value])
                    ->lockForUpdate()
                    ->exists();

                if ($hasConflict) {
                    throw new \InvalidArgumentException('يوجد موعد آخر مسجل بالفعل لهذا الطبيب في نفس هذا التوقيت.', 422);
                }
            }

            $patientId = $this->patientService->resolvePatient($data);

            return Appointment::create([
                'branch_id' => $branchId,
                'patient_id' => $patientId,
                'doctor_id' => $doctorId,
                'appointment_time' => $appointmentTime,
                'type' => $data['type'],
                'status' => $data['status'] ?? AppointmentStatus::BOOKING->value,
            ]);
        });
    }

    public function updateAppointment(string $id, array $data): Appointment
    {
        return DB::transaction(function () use ($id, $data) {
            $appointment = Appointment::lockForUpdate()->with('patient')->findOrFail($id);

            $newDoctorId = $data['doctor_id'] ?? $appointment->doctor_id;
            $newTime = $data['appointment_time'] ?? $appointment->appointment_time;
            $newBranchId = $data['branch_id'] ?? $appointment->branch_id;

            if ($newDoctorId && $newTime && (isset($data['appointment_time']) || isset($data['doctor_id']))) {
                $hasConflict = Appointment::where('branch_id', $newBranchId)
                    ->where('doctor_id', $newDoctorId)
                    ->where('appointment_time', $newTime)
                    ->where('id', '!=', $appointment->id)
                    ->whereNotIn('status', [AppointmentStatus::CANCELLED->value])
                    ->lockForUpdate()
                    ->exists();

                if ($hasConflict) {
                    throw new \InvalidArgumentException('يوجد موعد آخر مسجل بالفعل لهذا الطبيب في نفس هذا التوقيت.', 422);
                }
            }

            // Update appointment metadata
            $appointment->update(array_filter([
                'appointment_time' => $data['appointment_time'] ?? null,
                'type' => $data['type'] ?? null,
                'status' => $data['status'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'doctor_id' => $data['doctor_id'] ?? null,
            ], fn ($v) => ! is_null($v)));

            $strategy = $data['strategy'] ?? null;

            // Strategy 1: REASSIGN_EXISTING patient
            if ($strategy === 'REASSIGN_EXISTING' || (! empty($data['patient_id']) && $data['patient_id'] !== $appointment->patient_id)) {
                $targetPatientId = $data['patient_id'] ?? $this->patientService->resolvePatient($data);
                $appointment->update(['patient_id' => $targetPatientId]);
            }
            // Strategy 2: CREATE_AND_ASSIGN new patient profile (e.g. sibling/family member)
            elseif ($strategy === 'CREATE_AND_ASSIGN') {
                $patientPayload = $data['patient'] ?? $data;
                $newPatient = $this->patientService->createPatient($patientPayload);
                $appointment->update(['patient_id' => $newPatient->id]);
            }
            // Strategy 3: UPDATE_CURRENT patient's demographic details
            elseif ($strategy === 'UPDATE_CURRENT') {
                if ($appointment->patient_id && ! empty($data['patient'])) {
                    $this->patientService->updatePatientDetails($appointment->patient_id, $data['patient']);
                }
            }
            // BACKWARD COMPATIBILITY FALLBACK (Strategy omitted)
            elseif (! empty($data['patient'])) {
                $currentPatient = $appointment->patient;
                $incomingName = isset($data['patient']['name']) ? trim($data['patient']['name']) : null;
                $incomingPhone = isset($data['patient']['phone']) ? trim($data['patient']['phone']) : null;

                $isIdentityChanged = false;
                if ($currentPatient) {
                    if ($incomingName !== null && $incomingName !== $currentPatient->name) {
                        $isIdentityChanged = true;
                    }
                    if ($incomingPhone !== null && $incomingPhone !== $currentPatient->phone) {
                        $isIdentityChanged = true;
                    }
                } else {
                    $isIdentityChanged = true;
                }

                if ($isIdentityChanged) {
                    $targetPatientId = $this->patientService->resolvePatient($data);
                    $appointment->update(['patient_id' => $targetPatientId]);
                }
            }

            $appointment->load('patient');

            return $appointment;
        });
    }

    public function destroyAppointment(string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $appointment = Appointment::lockForUpdate()->findOrFail($id);

            $currentStatus = $appointment->status instanceof AppointmentStatus
                ? $appointment->status->value
                : $appointment->status;

            if ($currentStatus === AppointmentStatus::COMPLETED->value) {
                throw new \InvalidArgumentException('لا يمكن حذف موعد مكتمل بالفعل.');
            }

            LiveQueue::where('appointment_id', $appointment->id)->delete();

            return (bool) $appointment->delete();
        });
    }

    /**
     * Check-in an existing booked appointment into the live queue.
     */
    public function checkInAppointment(string $appointmentId): LiveQueue
    {
        return DB::transaction(function () use ($appointmentId) {
            $appointment = Appointment::lockForUpdate()->findOrFail($appointmentId);
            $currentStatus = $appointment->status instanceof AppointmentStatus
                ? $appointment->status->value
                : $appointment->status;

            if (in_array($currentStatus, [
                AppointmentStatus::CANCELLED->value,
                AppointmentStatus::COMPLETED->value,
            ])) {
                throw new \InvalidArgumentException('لا يمكن تسجيل حضور حجز ملغي أو مكتمل بالفعل.');
            }

            $appointment->update(['status' => AppointmentStatus::CHECKED_IN->value]);

            $existingQueue = LiveQueue::where('appointment_id', $appointment->id)->first();
            if ($existingQueue) {
                return $existingQueue;
            }

            return $this->liveQueueService->createNewPatientInQueue([
                'patient_id' => $appointment->patient_id,
                'appointment_id' => $appointment->id,
                'doctor_id' => $appointment->doctor_id,
            ], $appointment->branch_id);
        });
    }
}
