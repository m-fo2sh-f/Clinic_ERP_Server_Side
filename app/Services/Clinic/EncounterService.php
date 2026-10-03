<?php

namespace App\Services\Clinic;

use App\Enums\AppointmentStatus;
use App\Enums\EncounterStatus;
use App\Enums\EncounterType;
use App\Enums\LiveQueueStatus;
use App\Enums\PaymentStatus;
use App\Events\InvoiceReadyForPayment;
use App\Events\LiveQueueUpdated;
use App\Models\Appointment;
use App\Models\ClinicSetting;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\LiveQueue;
use App\Models\Patient;
use App\Models\Prescription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EncounterService
{
    protected BillingService $billingService;

    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    /**
     * Start a walk-in encounter atomically.
     * Handles inline patient lookup/creation and draft safeguards.
     */
    public function startWalkIn(array $data, int $doctorId, string $branchId): Encounter
    {
        return DB::transaction(function () use ($data, $doctorId, $branchId) {
            $patient = null;

            // 1. Patient resolution
            if (! empty($data['patient_id'])) {
                $patient = Patient::findOrFail($data['patient_id']);
            } else {
                // Inline patient creation
                $phone = $data['phone'] ?? null;
                if ($phone) {
                    $existingPatient = Patient::where('phone', $phone)->first();
                    if ($existingPatient) {
                        $patient = $existingPatient;
                    }
                }

                if (! $patient) {
                    $medicalNumber = 'MRN-'.date('Ymd').'-'.strtoupper(Str::random(4));
                    $patient = Patient::create([
                        'medical_number' => $medicalNumber,
                        'name' => $data['name'] ?? 'مريض غير مسجل',
                        'phone' => $data['phone'] ?? '0000000000',
                        'age' => $data['age'] ?? null,
                        'gender' => $data['gender'] ?? 'male',
                        'date_of_birth' => $data['date_of_birth'] ?? null,
                        'blood_group' => $data['blood_group'] ?? null,
                        'chronic_diseases' => $data['chronic_diseases'] ?? null,
                        'allergies' => $data['allergies'] ?? null,
                    ]);
                }
            }

            // 2. Draft Protection & No Blind Resumes
            $activeEncounter = Encounter::where('patient_id', $patient->id)
                ->where('doctor_id', $doctorId)
                ->whereIn('status', [EncounterStatus::DRAFT->value, EncounterStatus::IN_PROGRESS->value])
                ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
                ->first();

            if ($activeEncounter) {
                if (empty($data['resume_existing'])) {
                    throw new \InvalidArgumentException(
                        "المريض لديه جلسة فحص نشطة حالياً (ID: {$activeEncounter->id}). يرجى استئناف الجلسة أو إنهاؤها أولاً.",
                        409
                    );
                }

                return $activeEncounter->load(['patient', 'branch', 'doctor', 'prescription.items', 'invoice.items']);
            }

            // 3. Create fresh in_progress Encounter
            $encounter = Encounter::create([
                'branch_id' => $branchId,
                'patient_id' => $patient->id,
                'doctor_id' => $doctorId,
                'appointment_id' => $data['appointment_id'] ?? null,
                'type' => $data['type'] ?? EncounterType::WALK_IN->value,
                'status' => EncounterStatus::IN_PROGRESS->value,
                'chief_complaint' => $data['chief_complaint'] ?? null,
                'clinical_examination' => $data['clinical_examination'] ?? null,
                'diagnosis' => $this->normalizeDiagnosis($data['diagnosis'] ?? null),
                'vitals' => $this->sanitizeVitals($data['vitals'] ?? null, $branchId),
                'private_notes' => $data['private_notes'] ?? null,
                'started_at' => now(),
            ]);

            return $encounter->load(['patient', 'branch', 'doctor']);
        });
    }

    /**
     * Fast atomic save draft without row locks (deadlock prevention for 15s auto-save).
     */
    public function saveDraft(string $encounterId, array $data, int $doctorId): Encounter
    {
        $encounter = Encounter::where('id', $encounterId)->firstOrFail();

        // Guard against updating completed or cancelled encounters
        $statusValue = $encounter->status instanceof EncounterStatus
            ? $encounter->status->value
            : (string) $encounter->status;

        if (in_array($statusValue, [EncounterStatus::COMPLETED->value, EncounterStatus::CANCELLED->value])) {
            throw new \InvalidArgumentException('لا يمكن حفظ مسودة لجلسة فحص مكتملة أو ملغاة.', 409);
        }

        $updates = [];
        if (array_key_exists('chief_complaint', $data)) {
            $updates['chief_complaint'] = $data['chief_complaint'];
        }
        if (array_key_exists('clinical_examination', $data)) {
            $updates['clinical_examination'] = $data['clinical_examination'];
        }
        if (array_key_exists('diagnosis', $data)) {
            $updates['diagnosis'] = $this->normalizeDiagnosis($data['diagnosis']);
        }
        if (array_key_exists('vitals', $data)) {
            $updates['vitals'] = $this->sanitizeVitals($data['vitals'], $encounter->branch_id);
        }
        if (array_key_exists('private_notes', $data)) {
            $updates['private_notes'] = $data['private_notes'];
        }

        if (! empty($updates)) {
            $encounter->update($updates);
        }

        return $encounter->fresh(['patient', 'branch', 'doctor', 'prescription.items', 'invoice.items']);
    }

    /**
     * Complete encounter atomically with lockForUpdate().
     * Wraps clinical wrap-up, medication line-items, and BillingService checkout.
     *
     * @return array{encounter: Encounter, prescription: ?Prescription, invoice: ?Invoice}
     */
    public function completeEncounter(string $encounterId, array $data, int $doctorId): array
    {
        return DB::transaction(function () use ($encounterId, $data, $doctorId) {
            // Lock encounter for update to guard against concurrent completion
            $encounter = Encounter::lockForUpdate()->with(['patient', 'branch'])->findOrFail($encounterId);

            $statusValue = $encounter->status instanceof EncounterStatus
                ? $encounter->status->value
                : (string) $encounter->status;

            if ($statusValue === EncounterStatus::COMPLETED->value) {
                throw new \InvalidArgumentException('تم إنهاء هذه الجلسة الطبية مسبقاً.', 409);
            }

            // 1. Patient profile updates if provided
            if (! empty($data['patient_updates'])) {
                $patient = Patient::lockForUpdate()->find($encounter->patient_id);
                if ($patient) {
                    $patientUpdates = array_filter([
                        'blood_group' => $data['patient_updates']['blood_group'] ?? null,
                        'chronic_diseases' => $data['patient_updates']['chronic_diseases'] ?? null,
                        'allergies' => $data['patient_updates']['allergies'] ?? null,
                    ], fn ($v) => ! is_null($v) && $v !== '');

                    if (! empty($patientUpdates)) {
                        $patient->update($patientUpdates);
                    }
                }
            }

            // 2. Finalize Encounter record
            $encounter->update([
                'chief_complaint' => $data['chief_complaint'] ?? $encounter->chief_complaint,
                'clinical_examination' => $data['clinical_examination'] ?? $encounter->clinical_examination,
                'diagnosis' => $this->normalizeDiagnosis($data['diagnosis'] ?? $encounter->diagnosis),
                'vitals' => $this->sanitizeVitals($data['vitals'] ?? $encounter->vitals, $encounter->branch_id),
                'private_notes' => $data['private_notes'] ?? $encounter->private_notes,
                'status' => EncounterStatus::COMPLETED->value,
                'completed_at' => now(),
            ]);

            // 3. Batch Prescriptions if medications are provided
            $prescription = null;
            if (! empty($data['medications'])) {
                $prescriptionCode = 'RX-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

                $prescription = Prescription::create([
                    'encounter_id' => $encounter->id,
                    'appointment_id' => $encounter->appointment_id,
                    'patient_id' => $encounter->patient_id,
                    'doctor_id' => $doctorId,
                    'prescription_code' => $prescriptionCode,
                    'prescription_date' => now()->toDateString(),
                    'general_advice' => $data['general_advice'] ?? null,
                    'follow_up_date' => $data['follow_up_date'] ?? null,
                ]);

                $items = [];
                foreach ($data['medications'] as $index => $med) {
                    $items[] = [
                        'drug_id' => $med['drug_id'] ?? null,
                        'drug_name' => $med['drug_name'] ?? $med['name'] ?? '',
                        'dose' => $med['dose'] ?? $med['dosage'] ?? '',
                        'frequency' => $med['frequency'] ?? '',
                        'duration' => $med['duration'] ?? '',
                        'instruction' => $med['instruction'] ?? $med['instructions'] ?? null,
                        'sort_order' => $med['sort_order'] ?? $index,
                    ];
                }

                $prescription->items()->createMany($items);
                $prescription->load(['items', 'doctor']);
            }

            // 4. Billing Integration (Snapshot prices + Checkout)
            $invoice = null;
            $services = $data['services'] ?? [];
            $discount = (float) ($data['discount'] ?? 0.0);

            // Create or fetch encounter invoice
            $invoice = $this->billingService->createInvoiceForEncounter($encounter, $services, $discount);

            // If immediate checkout payment data is passed
            if (! empty($data['payments'])) {
                $invoice = $this->billingService->processPayment($invoice->id, $data['payments'], $doctorId);
            }

            // Sync Queue and Appointment state based on invoice payment status
            $isPaid = ($invoice->payment_status === PaymentStatus::PAID || (float) $invoice->total <= 0);

            $targetQueueStatus = $isPaid ? LiveQueueStatus::COMPLETED->value : LiveQueueStatus::PENDING_PAYMENT->value;
            $targetApptStatus = $isPaid ? AppointmentStatus::COMPLETED->value : AppointmentStatus::PENDING_PAYMENT->value;

            // Update associated live queue item
            $queueItem = LiveQueue::where('encounter_id', $encounter->id)
                ->orWhere(function ($q) use ($encounter) {
                    if ($encounter->appointment_id) {
                        $q->where('appointment_id', $encounter->appointment_id);
                    }
                })
                ->lockForUpdate()
                ->first();

            if ($queueItem) {
                $queueItem->update(['status' => $targetQueueStatus]);
            }

            if ($encounter->appointment_id) {
                Appointment::where('id', $encounter->appointment_id)
                    ->update(['status' => $targetApptStatus]);
            }

            $branchId = $encounter->branch_id;
            DB::afterCommit(function () use ($branchId, $invoice, $isPaid) {
                try {
                    event(new LiveQueueUpdated($branchId));
                    if (! $isPaid && $invoice) {
                        event(new InvoiceReadyForPayment($invoice));
                    }
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket broadcast failed in completeEncounter: '.$e->getMessage());
                }
            });

            return [
                'encounter' => $encounter->fresh(['patient', 'branch', 'doctor']),
                'prescription' => $prescription,
                'invoice' => $invoice?->fresh(['items', 'payments', 'patient']),
            ];
        });
    }

    /**
     * Initialize encounter from queue call.
     */
    public function startEncounterFromQueue(LiveQueue $queueItem, int $doctorId): Encounter
    {
        return DB::transaction(function () use ($queueItem, $doctorId) {
            if ($queueItem->encounter_id) {
                $existing = Encounter::find($queueItem->encounter_id);
                if ($existing && $existing->status === EncounterStatus::IN_PROGRESS) {
                    return $existing->load(['patient', 'branch', 'doctor']);
                }
            }

            $type = $queueItem->appointment_id ? EncounterType::SCHEDULED->value : EncounterType::WALK_IN->value;

            $encounter = Encounter::create([
                'branch_id' => $queueItem->branch_id,
                'patient_id' => $queueItem->patient_id,
                'doctor_id' => $doctorId,
                'appointment_id' => $queueItem->appointment_id,
                'type' => $type,
                'status' => EncounterStatus::IN_PROGRESS->value,
                'started_at' => now(),
            ]);

            return $encounter->load(['patient', 'branch', 'doctor']);
        });
    }

    /**
     * Retrieve any in_progress active encounter for the authenticated doctor in a branch.
     */
    public function getActiveEncounterForDoctor(int $doctorId, string $branchId): ?Encounter
    {
        return Encounter::where('doctor_id', $doctorId)
            ->where('branch_id', $branchId)
            ->where('status', EncounterStatus::IN_PROGRESS->value)
            ->with(['patient', 'branch', 'doctor', 'appointment', 'liveQueue', 'prescription.items', 'invoice.items'])
            ->latest('started_at')
            ->first();
    }

    /**
     * Abandon an encounter (Patient absence / Doctor abort).
     */
    public function abandonEncounter(string $encounterId, int $doctorId): Encounter
    {
        return DB::transaction(function () use ($encounterId, $doctorId) {
            $encounter = Encounter::lockForUpdate()->findOrFail($encounterId);

            if ($encounter->doctor_id !== $doctorId && ! auth()->user()->hasRole('clinic_owner')) {
                throw new \InvalidArgumentException('غير مصرح لك بإلغاء جلسة فحص تخص طبيباً آخر.', 403);
            }

            if ($encounter->status === EncounterStatus::COMPLETED) {
                throw new \InvalidArgumentException('لا يمكن إلغاء جلسة فحص مكتملة مسبقاً.', 409);
            }

            $encounter->update([
                'status' => EncounterStatus::CANCELLED->value,
                'completed_at' => now(),
            ]);

            // Update queue item to cancelled/no_show
            $queueItem = LiveQueue::where('encounter_id', $encounter->id)
                ->orWhere(function ($q) use ($encounter) {
                    if ($encounter->appointment_id) {
                        $q->where('appointment_id', $encounter->appointment_id);
                    }
                })
                ->lockForUpdate()
                ->first();

            if ($queueItem) {
                $queueItem->update(['status' => LiveQueueStatus::CANCELLED->value]);
            }

            if ($encounter->appointment_id) {
                Appointment::where('id', $encounter->appointment_id)
                    ->update(['status' => AppointmentStatus::CANCELLED->value]);
            }

            $branchId = $encounter->branch_id;
            DB::afterCommit(function () use ($branchId) {
                try {
                    event(new LiveQueueUpdated($branchId));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket broadcast failed in abandonEncounter: '.$e->getMessage());
                }
            });

            return $encounter->fresh(['patient', 'branch', 'doctor']);
        });
    }

    /**
     * Get aggregated today's summary for solo doctor workspace.
     */
    public function getTodaySummary(string $branchId, ?int $doctorId = null): array
    {
        $query = Encounter::where('branch_id', $branchId)
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()]);

        if ($doctorId) {
            $query->where('doctor_id', $doctorId);
        }

        $encounters = $query->with(['patient', 'invoice.payments', 'prescription.items'])
            ->orderByDesc('created_at')
            ->get();

        $completedCount = $encounters->where('status', EncounterStatus::COMPLETED)->count();
        $inProgressCount = $encounters->where('status', EncounterStatus::IN_PROGRESS)->count();

        $totalRevenue = 0.0;
        foreach ($encounters as $enc) {
            if ($enc->invoice && $enc->invoice->payments) {
                $totalRevenue += (float) $enc->invoice->payments->sum('amount');
            }
        }

        return [
            'total_encounters' => $encounters->count(),
            'completed_count' => $completedCount,
            'in_progress_count' => $inProgressCount,
            'total_revenue' => round($totalRevenue, 2),
            'encounters' => $encounters,
        ];
    }

    /**
     * Normalizes and validates the diagnosis array schema.
     * Accepts:
     * - Array of strings: ["Hypertension", "Type 2 Diabetes"]
     * - Array of uniform objects: [["code" => "I10", "description" => "Essential hypertension"]]
     */
    protected function normalizeDiagnosis(mixed $diagnosis): ?array
    {
        if (is_null($diagnosis)) {
            return null;
        }

        if (! is_array($diagnosis)) {
            if (is_string($diagnosis) && ! empty(trim($diagnosis))) {
                return [['description' => trim($diagnosis)]];
            }

            return null;
        }

        if (empty($diagnosis)) {
            return [];
        }

        $normalized = [];
        foreach ($diagnosis as $item) {
            if (is_string($item)) {
                $trimmed = trim($item);
                if ($trimmed !== '') {
                    $normalized[] = [
                        'code' => null,
                        'description' => $trimmed,
                    ];
                }
            } elseif (is_array($item)) {
                $desc = trim((string) ($item['description'] ?? $item['name'] ?? ''));
                if ($desc !== '') {
                    $normalized[] = [
                        'code' => isset($item['code']) ? trim((string) $item['code']) : null,
                        'description' => $desc,
                    ];
                }
            }
        }

        return $normalized;
    }

    /**
     * Safely sanitize and filter dynamic vitals against branch vitals_config.
     */
    protected function sanitizeVitals(?array $vitals, ?string $branchId = null): ?array
    {
        if ($vitals === null) {
            return null;
        }

        if ($branchId) {
            $setting = ClinicSetting::where('branch_id', $branchId)->first();
            if ($setting && ! empty($setting->vitals_config) && is_array($setting->vitals_config)) {
                $allowedKeys = [];
                foreach ($setting->vitals_config as $cfg) {
                    if (is_array($cfg) && isset($cfg['key'])) {
                        // If explicitly deactivated, skip
                        if ((isset($cfg['is_active']) && ! $cfg['is_active']) || (isset($cfg['enabled']) && ! $cfg['enabled'])) {
                            continue;
                        }
                        $allowedKeys[] = (string) $cfg['key'];
                    }
                }

                if (! empty($allowedKeys)) {
                    $filtered = [];
                    foreach ($vitals as $key => $val) {
                        if (in_array((string) $key, $allowedKeys, true)) {
                            $filtered[$key] = is_scalar($val) || is_null($val) ? $val : (is_array($val) ? $val : (string) $val);
                        }
                    }

                    return $filtered;
                }
            }
        }

        $safe = [];
        foreach ($vitals as $key => $val) {
            if (is_string($key) && (is_scalar($val) || is_null($val) || is_array($val))) {
                $safe[$key] = $val;
            }
        }

        return $safe;
    }
}
