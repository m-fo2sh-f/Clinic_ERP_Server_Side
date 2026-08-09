<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\LiveQueueStatus;
use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\LiveQueueService;
use App\Models\LiveQueue;
use App\Helpers\ShiftHelper;

class AppointmentService 
{
    private LiveQueueService $liveQueueService;

    public function __construct(LiveQueueService $liveQueueService)
    {
        $this->liveQueueService = $liveQueueService;
    }

    public function getAllAppointmentsForBranch(int|string $branchId, ?string $date = null)
    {
        [$startTime, $endTime] = ShiftHelper::getShiftWindow($date);

        return Appointment::where('branch_id', $branchId)
            ->with('patient')
            ->whereBetween('appointment_time', [$startTime, $endTime])
            ->orderBy('appointment_time', 'asc')
            ->get();
    }

    public function createAppointment(array $data): Appointment
    {
        return DB::transaction(function () use ($data) {
            if (!empty($data['patient_id'])) {
                $patientId = $data['patient_id'];
            } else {
                $patient = Patient::firstOrCreate(
                    ['phone' => trim($data['patient']['phone'])],
                    ['name'  => trim($data['patient']['name'])]
                );
                $patientId = $patient->id;
            }

            return Appointment::create([
                'branch_id'        => $data['branch_id'],
                'patient_id'       => $patientId,
                'appointment_time' => $data['appointment_time'],
                'type'             => $data['type'],
                'status'           => $data['status'] ?? AppointmentStatus::BOOKING->value,
            ]);
        });
    }

    public function updateAppointment(string $id, array $data): Appointment
    {
        return DB::transaction(function () use ($id, $data) {
            $appointment = Appointment::with('patient')->findOrFail($id);
            
            $appointment->update(array_filter([
                'appointment_time' => $data['appointment_time'] ?? null,
                'type'             => $data['type']             ?? null, 
                'status'           => $data['status']           ?? null,
                'branch_id'        => $data['branch_id']        ?? null,
            ]));

            if (!empty($data['patient']['phone'])) {
                $phone = trim($data['patient']['phone']);
                $name  = !empty($data['patient']['name']) ? trim($data['patient']['name']) : null;

                $currentPatient = $appointment->patient;
                $existingPatient = Patient::where('phone', $phone)->first();

                if ($existingPatient) {
                    $appointment->update(['patient_id' => $existingPatient->id]);
                    if ($name && $existingPatient->name !== $name) {
                        $existingPatient->update(['name' => $name]);
                    }
                } else {
                    $hasOtherAppointments = $currentPatient 
                        ? Appointment::where('patient_id', $currentPatient->id)
                            ->where('id', '!=', $appointment->id)
                            ->exists()
                        : false;

                    if ($hasOtherAppointments) {
                        $newPatient = Patient::create([
                            'phone' => $phone,
                            'name'  => $name ?? 'مريض جديد',
                        ]);
                        $appointment->update(['patient_id' => $newPatient->id]);
                    } else {
                        if ($currentPatient) {
                            $currentPatient->update([
                                'phone' => $phone,
                                'name'  => $name ?? $currentPatient->name,
                            ]);
                        } else {
                            $newPatient = Patient::create([
                                'phone' => $phone,
                                'name'  => $name ?? 'مريض جديد',
                            ]);
                            $appointment->update(['patient_id' => $newPatient->id]);
                        }
                    }
                }
            } elseif (!empty($data['patient_id']) && $data['patient_id'] !== $appointment->patient_id) {
                $appointment->update(['patient_id' => $data['patient_id']]);
            }

            $appointment->load('patient');
            return $appointment;
        });
    }

    public function destroyAppointment(string $id): bool
    {
        $appointment = Appointment::findOrFail($id);
        return (bool) $appointment->delete();
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
                'patient_id'     => $appointment->patient_id,
                'appointment_id' => $appointment->id,
            ], $appointment->branch_id);
        });
    }

    /**
     * Check-in a Walk-In patient with automatic Appointment SSOT ledger record creation.
     */
    public function checkInWalkIn(array $data, string $branchId): LiveQueue
    {
        return DB::transaction(function () use ($data, $branchId) {
            if (!empty($data['patient_id'])) {
                $patientId = $data['patient_id'];
            } else {
                $patient = Patient::firstOrCreate(
                    ['phone' => trim($data['patient']['phone'])],
                    ['name'  => trim($data['patient']['name'])]
                );
                $patientId = $patient->id;
            }

            // 1. Create permanent Appointment SSOT record for Walk-In
            $appointment = Appointment::create([
                'branch_id'        => $branchId,
                'patient_id'       => $patientId,
                'appointment_time' => now(),
                'type'             => 'check_up',
                'status'           => AppointmentStatus::CHECKED_IN->value,
            ]);

            // 2. Insert patient into live operational queue
            return $this->liveQueueService->createNewPatientInQueue([
                'patient_id'     => $patientId,
                'appointment_id' => $appointment->id,
            ], $branchId);
        });
    }
}