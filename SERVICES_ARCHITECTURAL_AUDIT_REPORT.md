# Comprehensive Services Architectural Audit Report

**Target Workspace:** `ServerSide/app/Services/`  
**Role:** Principal Enterprise Architect, Staff Security Engineer, Lead Database DBA  
**Date:** August 24, 2026  

---

## 🔬 SECTION 1: COMPARATIVE ARCHITECTURAL AUDIT (`updateAppointment`)

### 📊 Comparative Analysis Matrix

| Feature / Metric | Implementation A: Legacy Implicit Branching | Implementation B: Refactored Explicit Intent |
| :--- | :--- | :--- |
| **Concurrency Lock** | ❌ None (Vulnerable to dirty reads & lost updates) | ✅ Pessimistic Locking (`lockForUpdate()`) |
| **Domain Responsibility (SRP)** | ❌ Violated (Mutates Patient profiles inside `AppointmentService`) | ⚠️ Partial (Delegates to existing relation; ideally uses `PatientService`) |
| **Phone Matching Behavior** | ⚠️ Dynamic Lookup via `Patient::where('phone')` | ✅ Explicit via `patient_id` or existing relationship |
| **Data Integrity Risk** | 🚨 **CRITICAL**: Accidental patient profile renaming & medical record hijacking | 🟢 **SAFE**: Modifies only the linked patient's explicit details |
| **History Fragmentation** | 🚨 **HIGH**: Split patient profiles when phone number changes | 🟢 **SAFE**: Maintains immutable `patient_id` linkage |
| **Family Shared Phone** | 🚨 **HIGH**: Overwrites parent profile with child name or vice versa | 🟢 **SAFE**: Updates explicitly assigned patient profile |
| **Transaction Scope** | `DB::transaction` without pessimistic record locking | `DB::transaction` with `lockForUpdate()` |

---

## 🔍 SECTION 2: EVALUATION CRITERIA & REAL-WORLD CLINICAL FAILURE SCENARIOS

### 1. Silent Data Corruption & Accidental Profile Renaming
* **Scenario**: Patient A (John Doe, Phone `01000000000`) is in the system. Patient B (Jane Smith) has an appointment. A receptionist updates Jane's appointment and accidentally mistypes her phone number as `01000000000` with name "Jane Smith".
* **Implementation A Outcome**: `Patient::where('phone', '01000000000')->first()` finds John Doe. Implementation A rebinds Jane's appointment to John Doe **AND** updates John Doe's profile name to "Jane Smith". John Doe's medical history is now labeled as Jane Smith, causing catastrophic patient record overwrite!
* **Implementation B Outcome**: Modifies only Jane Smith's phone number or requires explicit `patient_id` re-assignment, preventing profile identity theft.

### 2. Medical History Fragmentation (Split Profiles)
* **Scenario**: Patient C changes phone number. 
* **Implementation A Outcome**: If `hasOtherAppointments` is true, Implementation A spawns a brand new `Patient` row ("مريض جديد") for the same human being! Past medical history, allergies, chronic conditions, and past prescriptions remain attached to the old `Patient` record, while the new appointment is attached to the orphaned new profile.
* **Implementation B Outcome**: Keeps the existing `patient_id` on the appointment and updates the phone number on the existing `Patient` record, keeping all clinical history linked to a single Single Source of Truth (SSOT).

### 3. Family Member Shared Phone Collision
* **Scenario**: A mother and child share the same contact number (`01222222222`).
* **Implementation A Outcome**: Updating the child's appointment using the shared phone number matches the mother's existing `Patient` record, renames the mother to the child's name, or re-links the appointment to the mother, corrupting demographic and pediatric records.
* **Implementation B Outcome**: Preserves the parent/child `patient_id` distinction and updates designated patient details without cross-patient phone lookup hijacking.

### 4. Concurrency & Race Conditions
* **Scenario**: Two receptionists simultaneously edit the same appointment.
* **Implementation A**: Lacks pessimistic locking (`lockForUpdate()`). Simultaneous updates cause race conditions, lost updates, and phantom reads.
* **Implementation B**: Uses `Appointment::lockForUpdate()`, enforcing thread safety at the DB row level.

---

## 📂 SECTION 3: FULL SERVICE LAYER AUDIT (`ServerSide/app/Services/`)

### 1. `AppointmentService.php`
* 🚨 **Anti-Pattern**: Contains implicit branching for Patient creation/updating inside `updateAppointment()`.
* 🚨 **Concurrency Risk**: `createAppointment` and `checkInWalkIn` perform non-locked checks/creations.
* ⚠️ **SRP Violation**: Mixed responsibility between appointment ledger control and patient identity resolution.

### 2. `LiveQueueService.php`
* ✅ **Good Practice**: Uses `branches` row locking (`DB::table('branches')->where('id', $branchId)->lockForUpdate()`) to avoid gap locks/deadlocks.
* ✅ **Good Practice**: `DB::afterCommit()` used for WebSocket event broadcasting.
* ⚠️ **Query Optimization**: Negative `queue_no` swapping in `reorderQueue` is effective for unique constraints, but statement execution can be optimized via bulk upsert routines.

### 3. `ConsultationService.php`
* ✅ **Transaction Resilience**: Atomically locks `LiveQueue`, `Patient`, and `Appointment`.
* ✅ **Event Safety**: Uses `DB::afterCommit()` for events.
* ⚠️ **Redundancy**: Manual string random generator for prescription code (`RX-...`) should use sequence locks or UUIDs to avoid potential collisions under high concurrency.

### 4. `PatientService.php`
* ⚠️ **Missing Concurrency Safeguards**: `resolvePatient` uses `firstOrCreate()` without explicit locks, which under concurrent requests with the same phone number can cause MySQL duplicate key exceptions.

---

## 📋 SECTION 4: RECOMMENDED PRODUCTION-READY REFACTORED SERVICES

Below are the recommended architecture-compliant service implementations adhering to SRP, Pessimistic Locking, and Clean Code principles.

### `app/Services/PatientService.php`
```php
<?php

namespace App\Services;

use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PatientService
{
    public function getTenantPatients(?string $branchId = null, ?string $search = null): Collection
    {
        $query = Patient::query();

        if (!empty($search)) {
            $searchTerm = trim($search);
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('phone', 'LIKE', "%{$searchTerm}%");
            });
        }

        return $query->withCount([
            'appointments as total_completed_count' => fn ($q) => $q->where('status', 'completed'),
            'appointments as branch_completed_count' => function ($q) use ($branchId) {
                $q->where('status', 'completed');
                if (!empty($branchId)) {
                    $q->where('branch_id', $branchId);
                }
            },
            'appointments as completed_appointments_count' => fn ($q) => $q->where('status', 'completed')
        ])
        ->orderByDesc('created_at')
        ->get(['id', 'name', 'phone', 'age', 'gender', 'medical_history', 'created_at']);
    }

    public function search(Request $request): Collection
    {
        $query = trim($request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return new Collection();
        }

        return Patient::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                  ->orWhere('phone', 'LIKE', "%{$query}%");
            })
            ->select(['id', 'name', 'phone'])
            ->limit(10)
            ->get();
    }

    public function show(string $id): Patient
    {
        return Patient::withCount(['appointments as completed_appointments_count' => function ($q) {
            $q->where('status', 'completed');
        }])
        ->with([
            'appointments' => function ($query) {
                $query->orderBy('appointment_time', 'desc')->with('branch');
            },
        ])
        ->findOrFail($id);
    }

    /**
     * Resolve existing patient by explicit patient_id or create/find safely.
     */
    public function resolvePatient(array $data): string
    {
        if (!empty($data['patient_id'])) {
            return $data['patient_id'];
        }

        $phone = trim($data['patient']['phone'] ?? $data['phone']);
        $name  = trim($data['patient']['name'] ?? $data['name'] ?? 'مريض جديد');

        return DB::transaction(function () use ($phone, $name) {
            $patient = Patient::where('phone', $phone)->lockForUpdate()->first();

            if ($patient) {
                return $patient->id;
            }

            $newPatient = Patient::create([
                'phone' => $phone,
                'name'  => $name,
            ]);

            return $newPatient->id;
        });
    }

    /**
     * Explicitly update patient demography safely.
     */
    public function updatePatientDetails(string $patientId, array $data): Patient
    {
        return DB::transaction(function () use ($patientId, $data) {
            $patient = Patient::lockForUpdate()->findOrFail($patientId);

            $updates = array_filter([
                'name'  => !empty($data['name']) ? trim($data['name']) : null,
                'phone' => !empty($data['phone']) ? trim($data['phone']) : null,
            ]);

            if (!empty($updates)) {
                $patient->update($updates);
            }

            return $patient;
        });
    }
}
```

### `app/Services/AppointmentService.php`
```php
<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\LiveQueue;
use App\Helpers\ShiftHelper;
use Illuminate\Support\Facades\DB;

class AppointmentService 
{
    private LiveQueueService $liveQueueService;
    private PatientService $patientService;

    public function __construct(LiveQueueService $liveQueueService, PatientService $patientService)
    {
        $this->liveQueueService = $liveQueueService;
        $this->patientService = $patientService;
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
            $patientId = $this->patientService->resolvePatient($data);

            return Appointment::create([
                'branch_id'        => $data['branch_id'],
                'patient_id'       => $patientId,
                'appointment_time' => $data['appointment_time'],
                'type'             => $data['type'],
                'status'           => $data['status'] ?? AppointmentStatus::BOOKING->value,
            ]);
        });
    }

    /**
     * Refactored Explicit Intent Implementation B with SRP & DB Locking
     */
    public function updateAppointment(string $id, array $data): Appointment
    {
        return DB::transaction(function () use ($id, $data) {
            $appointment = Appointment::lockForUpdate()->with('patient')->findOrFail($id);

            $appointment->update(array_filter([
                'appointment_time' => $data['appointment_time'] ?? null,
                'type'             => $data['type']             ?? null, 
                'status'           => $data['status']           ?? null,
                'branch_id'        => $data['branch_id']        ?? null,
            ]));

            // Explicit re-assignment of patient_id
            if (!empty($data['patient_id']) && $data['patient_id'] !== $appointment->patient_id) {
                $appointment->update(['patient_id' => $data['patient_id']]);
            } 
            // Delegate Patient details mutation safely via PatientService
            elseif (!empty($data['patient']) && $appointment->patient) {
                $this->patientService->updatePatientDetails($appointment->patient_id, $data['patient']);
            }

            $appointment->load('patient');
            return $appointment;
        });
    }

    public function destroyAppointment(string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $appointment = Appointment::lockForUpdate()->findOrFail($id);

            if ($appointment->status === AppointmentStatus::COMPLETED->value) {
                throw new \InvalidArgumentException('لا يمكن حذف موعد مكتمل بالفعل.');
            }

            LiveQueue::where('appointment_id', $appointment->id)->delete();

            return (bool) $appointment->delete();
        });
    }

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

    public function checkInWalkIn(array $data, string $branchId): LiveQueue
    {
        return DB::transaction(function () use ($data, $branchId) {
            $patientId = $this->patientService->resolvePatient($data);

            $appointment = Appointment::create([
                'branch_id'        => $branchId,
                'patient_id'       => $patientId,
                'appointment_time' => now(),
                'type'             => $data['type'] ?? 'check_up',
                'status'           => AppointmentStatus::CHECKED_IN->value,
            ]);

            return $this->liveQueueService->createNewPatientInQueue([
                'patient_id'     => $patientId,
                'appointment_id' => $appointment->id,
            ], $branchId);
        });
    }
}
```
