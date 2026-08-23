<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\LiveQueueStatus;
use App\Events\LiveQueueUpdated;
use App\Models\Appointment;
use App\Models\LiveQueue;
use App\Models\Patient;
use App\Models\Prescription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsultationService
{
    /**
     * Complete the full clinical consultation workflow inside an atomic transaction.
     *
     * Steps:
     * 1. Lock the LiveQueue record
     * 2. Update Patient demographics if patient_updates provided
     * 3. Update Appointment with clinical data (vitals JSON as SSOT, diagnosis array) and mark completed
     * 4. Create Prescription with line-item medications
     * 5. Mark LiveQueue as completed
     * 6. Dispatch WebSocket events after commit
     *
     * @param  array  $data  Validated payload from CompleteConsultationRequest
     * @return array{appointment: Appointment, prescription: Prescription}
     */
    public function completeConsultation(array $data): array
    {
        return DB::transaction(function () use ($data) {
            // 1. Lock the queue entry to prevent concurrent completions
            $queueItem = LiveQueue::lockForUpdate()->findOrFail($data['live_queue_id']);

            // Guard: prevent re-completing an already-completed queue item
            if ($queueItem->status === LiveQueueStatus::COMPLETED) {
                throw new \InvalidArgumentException('This consultation has already been completed.');
            }

            // 2. Update Patient demographic information if provided
            if (!empty($data['patient_updates'])) {
                $patient = Patient::lockForUpdate()->find($data['patient_id']);
                if ($patient) {
                    $updates = array_filter([
                        'blood_group'      => $data['patient_updates']['blood_group'] ?? null,
                        'chronic_diseases' => $data['patient_updates']['chronic_diseases'] ?? null,
                        'allergies'        => $data['patient_updates']['allergies'] ?? null,
                    ], fn ($val) => !is_null($val) && $val !== '');
                    if (!empty($updates)) {
                        $patient->update($updates);
                    }
                }
            }

            // 3. Update Appointment with clinical findings, vitals JSON (SSOT), diagnosis array, and mark completed
            $appointment = Appointment::lockForUpdate()->findOrFail($data['appointment_id']);

            $appointment->update([
                'chief_complaint'      => $data['chief_complaint'],
                'diagnosis'            => $data['diagnoses'], // Native array cast (no manual json_encode)
                'clinical_examination' => $data['examination_findings'] ?? null,
                'vitals'               => $data['vitals'] ?? null, // SSOT JSON for all vital signs
                'status'               => AppointmentStatus::COMPLETED->value,
                'completed_at'         => now(),
            ]);

            // 4. Create Prescription record
            $prescriptionCode = 'RX-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

            $prescription = Prescription::create([
                'appointment_id'    => $appointment->id,
                'patient_id'        => $data['patient_id'],
                'doctor_id'         => auth()->id(),
                'prescription_code' => $prescriptionCode,
                'prescription_date' => now()->toDateString(),
                'general_advice'    => $data['general_advice'] ?? null,
                'follow_up_date'    => $data['follow_up_date'] ?? null,
            ]);

            // 5. Create PrescriptionItem records (bulk)
            if (!empty($data['medications'])) {
                $items = [];
                foreach ($data['medications'] as $index => $med) {
                    $dose = $med['dosage'] ?? $med['dose'] ?? '';
                    $instruction = $med['instructions'] ?? $med['instruction'] ?? null;

                    $items[] = [
                        'id'              => Str::uuid()->toString(),
                        'prescription_id' => $prescription->id,
                        'drug_id'         => $med['drug_id'] ?? null,
                        'drug_name'       => $med['name'],
                        'dose'            => $dose,
                        'frequency'       => $med['frequency'] ?? '',
                        'duration'        => $med['duration'] ?? '',
                        'instruction'     => $instruction,
                        'sort_order'      => $med['sort_order'] ?? $index,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ];
                }
                DB::table('prescription_items')->insert($items);
            }

            // 6. Mark LiveQueue entry as completed
            $queueItem->update(['status' => LiveQueueStatus::COMPLETED->value]);

            // 7. Dispatch WebSocket events safely after commit
            $branchId = $queueItem->branch_id;
            DB::afterCommit(function () use ($branchId) {
                try {
                    event(new LiveQueueUpdated($branchId));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket broadcast failed in completeConsultation: ' . $e->getMessage());
                }
            });

            // Eager-load relationships for the response
            $appointment->load(['patient', 'branch']);
            $prescription->load(['items', 'doctor']);

            return [
                'appointment'  => $appointment,
                'prescription' => $prescription,
            ];
        });
    }

    /**
     * Get enriched patient history with prescriptions for the Doctor Dashboard.
     *
     * @param  string  $patientId
     * @return Patient
     */
    public function getPatientHistory(string $patientId): Patient
    {
        return Patient::withCount([
            'appointments as completed_appointments_count' => function ($q) {
                $q->where('status', 'completed');
            },
            'appointments as total_completed_count' => function ($q) {
                $q->where('status', 'completed');
            },
        ])
        ->with([
            'appointments' => function ($query) {
                $query->orderBy('appointment_time', 'desc')
                    ->with(['branch', 'prescription.items', 'prescription.doctor']);
            },
        ])
        ->findOrFail($patientId);
    }
}
