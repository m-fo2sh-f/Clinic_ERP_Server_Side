# 🏗️ Core Refactor Blueprint: Solo Doctor & Polyclinic Unification

**Architectural Specification & Implementation Plan**  
*Document Version: 1.0.0 — Planning & Blueprint Phase*  
*Multi-Tenant Clinic ERP (Laravel 11 + Stancl Tenancy DB-per-Tenant | React Vite + Tailwind CSS)*

---

## 🎯 Role & Objective
Unified architecture for our multi-tenant Clinic ERP enabling it to operate seamlessly under two modes:
1. `solo`: Single doctor, walk-in immediate consultation, direct billing, no reception/queue dependency.
2. `polyclinic`: Full reception desk, scheduling, live queue, separated billing, multi-doctor workflows.

---

## 1. Database & Migrations Roadmap (`database/migrations/tenant/`)

Because the application is in active development with no legacy patient data requiring backward compatibility migrations, architectural cleanliness is preserved by introducing the FHIR-decoupled `encounters` entity and aligning related tenant tables.

```
┌────────────────────────────────────────────────────────────────────────┐
│                        FHIR Decoupled Architecture                     │
├──────────────────────────────┬─────────────────────────────────────────┤
│ Polyclinic Mode              │ Solo Mode                               │
│  [Appointment (Booking)]     │                                         │
│            │                 │                                         │
│            ▼                 │                                         │
│     [Live Queue]             │                                         │
│            │                 │                                         │
│            ▼                 ▼                                         │
│       [Encounter (Actual Clinical Visit)] <── appointment_id: NULL     │
│                 ├── vitals (JSON SSOT)                                 │
│                 ├── chief_complaint, examination, diagnosis            │
│                 ├── Prescriptions (encounter_id)                       │
│                 └── Invoices (encounter_id + snapshot unit_price)      │
└────────────────────────────────────────────────────────────────────────┘
```

### 1.1 New Migration: `create_tenant_encounters_table`
*File Target:* `database/migrations/tenant/2026_01_01_000008_create_tenant_encounters_table.php`

```php
Schema::create('encounters', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
    $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
    $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
    
    // Decoupled linkage: Nullable for Solo walk-ins; references appointment in polyclinic
    $table->foreignUuid('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();

    // Type & Status (Backed by PHP 8.1 Enums)
    $table->string('type')->default('walk_in');       // walk_in, check_up, follow_up, emergency
    $table->string('status')->default('in_progress'); // draft, in_progress, completed, cancelled

    // Clinical Core (FHIR Encounter Decoupling)
    $table->text('chief_complaint')->nullable();
    $table->text('clinical_examination')->nullable();
    $table->json('diagnosis')->nullable();            // Array of diagnosis strings or structured ICD objects
    $table->json('vitals')->nullable();               // Dynamic key-values formatted per vitals_config
    $table->text('private_notes')->nullable();        // Doctor's private observations

    // Timestamps
    $table->timestamp('started_at')->useCurrent();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();

    // Performance Indexes
    $table->index(['branch_id', 'status', 'created_at'], 'idx_encounters_branch_status_created');
    $table->index(['patient_id', 'created_at'], 'idx_encounters_patient_created');
    $table->index(['doctor_id', 'created_at'], 'idx_encounters_doctor_created');
    $table->index('appointment_id', 'idx_encounters_appointment');
});
```

### 1.2 Updated Migration: `create_tenant_clinic_settings_table`
*File Target:* `database/migrations/tenant/2026_01_01_000005_create_tenant_clinic_settings_table.php`

Add `clinic_mode` and `vitals_config`:
```php
Schema::create('clinic_settings', function (Blueprint $table) {
    $table->id();
    $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
    $table->string('clinic_mode')->default('polyclinic'); // 'solo' or 'polyclinic'
    $table->string('queue_strategy')->default('hybrid'); 
    $table->integer('avg_appointment_duration')->default(15);
    $table->json('vitals_config')->nullable(); // Configurable dynamic vitals schema
    $table->timestamps();

    $table->unique('branch_id');
});
```

**Default JSON Structure for `vitals_config`:**
```json
[
  { "key": "bp_systolic", "label": "Blood Pressure (Systolic)", "unit": "mmHg", "type": "number", "required": false },
  { "key": "bp_diastolic", "label": "Blood Pressure (Diastolic)", "unit": "mmHg", "type": "number", "required": false },
  { "key": "heart_rate", "label": "Heart Rate", "unit": "bpm", "type": "number", "required": false },
  { "key": "temperature", "label": "Body Temperature", "unit": "°C", "type": "number", "step": "0.1", "required": false },
  { "key": "respiratory_rate", "label": "Respiratory Rate", "unit": "bpm", "type": "number", "required": false },
  { "key": "spo2", "label": "Oxygen Saturation (SpO2)", "unit": "%", "type": "number", "required": false },
  { "key": "weight", "label": "Weight", "unit": "kg", "type": "number", "step": "0.1", "required": false },
  { "key": "height", "label": "Height", "unit": "cm", "type": "number", "required": false }
]
```

### 1.3 Updated Migration: `create_tenant_appointments_table`
*File Target:* `database/migrations/tenant/2026_01_01_000006_create_tenant_appointments_table.php`

Purge clinical columns (`chief_complaint`, `diagnosis`, `clinical_examination`, `vitals`, `started_at`, `completed_at`) so `appointments` strictly represents the scheduling intent.
```php
Schema::create('appointments', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
    $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
    $table->foreignId('doctor_id')->nullable()->constrained('users')->nullOnDelete();
    $table->dateTime('appointment_time');
    $table->string('type')->default('check_up');
    $table->string('status')->default('booking'); // booking, checked_in, completed, no_show, canceled
    $table->timestamps();

    $table->index(['branch_id', 'appointment_time'], 'idx_appts_branch_time');
    $table->index(['branch_id', 'doctor_id', 'appointment_time'], 'idx_appts_branch_doc_time');
    $table->index(['branch_id', 'status', 'appointment_time'], 'idx_appts_branch_status_time');
    $table->index(['doctor_id', 'appointment_time'], 'idx_appts_doctor_time');
    $table->index(['patient_id', 'status'], 'idx_appts_patient_status');
});
```

### 1.4 Decoupling Updates: `prescriptions` & `invoices`
1. **Prescriptions** (`2026_01_01_000010_create_tenant_prescriptions_table.php`):
   - Add `$table->foreignUuid('encounter_id')->nullable()->constrained('encounters')->cascadeOnDelete();`
   - Modify `$table->foreignUuid('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();`
   - Add index on `encounter_id`.
2. **Invoices** (`2026_01_01_000014_create_tenant_invoices_table.php`):
   - Add `$table->foreignUuid('encounter_id')->nullable()->constrained('encounters')->nullOnDelete();`
   - `$table->foreignUuid('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();`
   - Add index on `encounter_id`.

---

## 2. Backend Domain & Architecture (Laravel 11)

### 2.1 PHP 8.1 Enums (`app/Enums/`)

```php
// app/Enums/ClinicMode.php
namespace App\Enums;

enum ClinicMode: string {
    case SOLO = 'solo';
    case POLYCLINIC = 'polyclinic';

    public static function values(): array {
        return array_column(self::cases(), 'value');
    }
}

// app/Enums/EncounterStatus.php
namespace App\Enums;

enum EncounterStatus: string {
    case DRAFT       = 'draft';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED   = 'completed';
    case CANCELLED   = 'cancelled';

    public static function values(): array {
        return array_column(self::cases(), 'value');
    }
}

// app/Enums/EncounterType.php
namespace App\Enums;

enum EncounterType: string {
    case WALK_IN   = 'walk_in';
    case CHECK_UP  = 'check_up';
    case FOLLOW_UP = 'follow_up';
    case EMERGENCY = 'emergency';

    public static function values(): array {
        return array_column(self::cases(), 'value');
    }
}
```

### 2.2 Eloquent Models & Casts

| Model | Casts & Key Attributes | Relationships |
|---|---|---|
| `Encounter` | `type => EncounterType`<br>`status => EncounterStatus`<br>`diagnosis => 'array'`<br>`vitals => 'array'`<br>`started_at => 'datetime'`<br>`completed_at => 'datetime'` | `belongsTo(Branch::class)`<br>`belongsTo(Patient::class)`<br>`belongsTo(User::class, 'doctor_id')`<br>`belongsTo(Appointment::class)`<br>`hasOne(Prescription::class)`<br>`hasOne(Invoice::class)` |
| `Prescription` | `prescription_date => 'date'`<br>`follow_up_date => 'date'` | `belongsTo(Encounter::class)`<br>`belongsTo(Appointment::class)`<br>`belongsTo(Patient::class)`<br>`belongsTo(User::class, 'doctor_id')`<br>`hasMany(PrescriptionItem::class)` |
| `Invoice` | `payment_status => PaymentStatus`<br>`subtotal, discount, total => 'decimal:2'` | `belongsTo(Encounter::class)`<br>`belongsTo(Appointment::class)`<br>`belongsTo(Patient::class)`<br>`belongsTo(Branch::class)`<br>`hasMany(InvoiceItem::class)`<br>`hasMany(Payment::class)` |
| `Patient` | `date_of_birth => 'date'` | `hasMany(Encounter::class)`<br>`hasMany(Appointment::class)`<br>`hasMany(Prescription::class)`<br>`hasMany(Invoice::class)` |
| `Appointment` | `status => AppointmentStatus`<br>`appointment_time => 'datetime'` | `belongsTo(Patient::class)`<br>`belongsTo(Branch::class)`<br>`belongsTo(User::class, 'doctor_id')`<br>`hasOne(Encounter::class)` |
| `ClinicSetting` | `clinic_mode => ClinicMode`<br>`vitals_config => 'array'` | `belongsTo(Branch::class)` |

### 2.3 `EncounterService` Architecture
*Location:* `app/Services/Clinic/EncounterService.php`

```
  ┌────────────────────────────────────────────────────────────────────────┐
  │                           EncounterService                             │
  └───────┬──────────────────────────┬──────────────────────────┬──────────┘
          │                          │                          │
          ▼                          ▼                          ▼
   startWalkIn(...)            saveDraft(...)         completeEncounter(...)
   ├── Lookup/Create Patient   ├── Lock for update    ├── Lock for update
   ├── Check active draft      ├── Guard: !COMPLETED  ├── Save clinical record
   └── Init Encounter          └── Update vitals/notes├── Create Prescription & Items
                                                      └── Integrate BillingService:
                                                          ├── Generate Invoice (snapshot price)
                                                          └── Process Payment (Cash/Visa/Split)
```

1. **`startWalkIn(array $patientData, ?string $patientId, array $encounterData, int $doctorId, string $branchId): Encounter`**
   - Atomic transaction.
   - If `$patientId` provided: lock and verify existence.
   - If `$patientId` is null: create new `Patient` with generated medical record number (`MRN-XXXXXX`).
   - Guard against duplicate active encounter: If patient already has an `in_progress` encounter with the doctor today, resume that encounter.
   - Create `Encounter` with `status = EncounterStatus::IN_PROGRESS`, `type = EncounterType::WALK_IN`, `appointment_id = null`.

2. **`saveDraft(string $encounterId, array $data): Encounter`**
   - Query encounter with `lockForUpdate()`.
   - Guard: throw `\InvalidArgumentException` if encounter status is `completed` or `cancelled`.
   - Update `chief_complaint`, `clinical_examination`, `diagnosis`, `vitals`, and `private_notes`.

3. **`completeEncounter(string $encounterId, array $data, int $doctorId): array`**
   - Wrap in `DB::transaction()`.
   - Lock `Encounter` for update. Guard against already completed status.
   - Persist final clinical findings, vitals, diagnosis, and set `status = EncounterStatus::COMPLETED`, `completed_at = now()`.
   - Update patient baseline clinical metrics if supplied (`blood_group`, `allergies`, `chronic_diseases`).
   - If `medications` array present:
     - Generate prescription code `RX-YYYYMMDD-XXXXXX`.
     - Create `Prescription` linked to `encounter_id`.
     - Batch create `PrescriptionItem` lines with sort order.
   - **Billing & Checkout Integration (delegated to `BillingService`):**
     - If billable services provided or consultation fee charged:
       - Create `Invoice` linked to `encounter_id` (snapshot `unit_price` on `invoice_items`).
       - If `payment` payload provided (`method`, `amount`, `transaction_reference`): invoke `BillingService::processPayment` to create `Payment` records and mark invoice `paid`.
     - If no payment details provided: mark invoice `unpaid` / `pending_payment`.
   - Return composite DTO: `['encounter' => $encounter, 'prescription' => $prescription, 'invoice' => $invoice]`.

4. **`getTodaySummary(string $branchId, ?int $doctorId = null): array`**
   - Fetch today's completed and in-progress encounters with eager-loaded `patient`, `invoice.payments`, and `prescription`.
   - Compute metrics: total encounters today, total billings collected today, active drafts.

### 2.4 API Endpoints & Form Requests (`routes/tenant.php`)

```php
// Under Route::prefix('api/v1')->middleware(['auth:sanctum', 'tenant.user'])

// 🩺 Patients Quick Discovery
Route::get('patients/search', [PatientController::class, 'search']); // ?query=
Route::get('patients/{id}/medical-profile', [PatientController::class, 'medicalProfile']);

// ⚡ Encounter Lifecycle (Solo & Polyclinic Unified)
Route::prefix('encounters')->middleware('role:doctor|clinic_owner')->group(function () {
    Route::post('quick-start', [EncounterController::class, 'quickStart']);
    Route::patch('{id}/draft', [EncounterController::class, 'saveDraft']);
    Route::post('{id}/complete', [EncounterController::class, 'complete']);
    Route::get('today-summary', [EncounterController::class, 'todaySummary']);
    Route::get('{id}', [EncounterController::class, 'show']);
});
```

### 2.5 Auth Payload Update (`AuthController::login` & `AuthController::me`)
Inject `clinic_mode` and `vitals_config` into tenant/branch payload:
```json
{
  "user": { ... },
  "branches": [
    {
      "id": "uuid-...",
      "name": "Main Branch",
      "clinic_mode": "solo",
      "vitals_config": [ ... ],
      "queue_strategy": "hybrid"
    }
  ],
  "active_branch_settings": {
    "clinic_mode": "solo",
    "vitals_config": [ ... ]
  }
}
```

---

## 3. Frontend Architecture & Flow (React + Vite)

### 3.1 State & Mode Detection Architecture

```
                      ┌───────────────────────────┐
                      │    API Response (/me)     │
                      └─────────────┬─────────────┘
                                    │
                                    ▼
                      ┌───────────────────────────┐
                      │      BranchContext        │
                      │  - activeBranch           │
                      │  - clinic_mode: 'solo'    │
                      │  - vitals_config: [...]   │
                      └─────────────┬─────────────┘
                                    │
                  ┌─────────────────┴─────────────────┐
                  ▼                                   ▼
        clinic_mode === 'solo'              clinic_mode === 'polyclinic'
  ┌───────────────────────────────┐   ┌───────────────────────────────┐
  │ Doctor View:                  │   │ Doctor View:                  │
  │  -> SoloWorkspaceView         │   │  -> DoctorDashboard (Queue)   │
  │ Sidebar Items:                │   │ Sidebar Items:                │
  │  - Clinical Workspace         │   │  - Receptionist Dashboard     │
  │  - Patients Directory         │   │  - Queue / Waiting Room       │
  │  - Services & Pricing         │   │  - Doctor Consultation        │
  │  - Today's Encounters Drawer  │   │  - Billing & Cashier Desk     │
  └───────────────────────────────┘   └───────────────────────────────┘
```

1. **Context Modernization:**
   - Expose `clinicMode` (`'solo' | 'polyclinic'`) and `vitalsConfig` from `useBranchContext()`.
2. **Conditional Routing in `AppRoutes.jsx`:**
   - If `currentUser.role === 'doctor'`:
     - If `clinicMode === 'solo'`: redirect `/doctor` or `/dashboard` to `SoloWorkspaceView`.
     - If `clinicMode === 'polyclinic'`: route `/doctor` to `DoctorDashboard` and `/dashboard` to `ReceptionistDashboard`.
3. **Sidebar Menu Filtering in `DashboardLayout.jsx`:**
   - In `solo` mode, hide Queue, Live Reception, and Waiting Room links. Show:
     - 🩺 **Clinical Workspace** (`/doctor`)
     - 👥 **Patients Directory** (`/patients`)
     - ⚙️ **Services & Pricing** (`/settings`)
     - 📑 **Today's Encounters** (drawer toggle)

### 3.2 Layout & Component Architecture (`SoloWorkspaceView`)

```
┌─────────────────────────────────────────────────────────────────────────────────────────────────┐
│                                   SoloWorkspaceView Layout                                      │
├─────────────────────────────────────────────────────────────────────────────────────────────────┤
│ [🔍 Search Patient (Name/Phone/MRN)...]  [➕ Quick Add Patient]   [📑 Today: 8 Encounters | 3.2k EGP]│
├───────────────────────────────────────────────────┬─────────────────────────────────────────────┤
│ 🩺 Active Patient Banner                          │ 💳 Billing & Quick Checkout                 │
│  Patient: Ahmed Mohamed (Male, 34) | MRN-00412    │  - Consultation Fee: 150 EGP (default)      │
│  Allergies: Penicillin | Blood: O+                │  - Add Service: [+ ECG (100 EGP)]           │
├───────────────────────────────────────────────────┤  - Total: 250 EGP                           │
│ 1. Dynamic Vitals Form (driven by vitals_config)  │  [⚡ Complete & Pay (Cash / Visa / Split)]  │
│  [BP Systolic] [BP Diastolic] [HR] [Temp] [SpO2]  ├─────────────────────────────────────────────┤
├───────────────────────────────────────────────────┤ 🕒 Auto-Save Status:                        │
│ 2. Clinical Notes & Findings                      │    🟢 All changes saved (Draft #102)        │
│  - Chief Complaint (Textarea)                     │                                             │
│  - Clinical Examination (Textarea)                │                                             │
│  - Diagnoses (Multi-input tag chips)              │                                             │
├───────────────────────────────────────────────────┤                                             │
│ 3. Prescription Builder                           │                                             │
│  [Drug Name] [Dose] [Freq] [Duration] [Instr] [+] │                                             │
│  - Amoxicillin 500mg | 1 tab | 8 hrs | 7 days [✕] │                                             │
└───────────────────────────────────────────────────┴─────────────────────────────────────────────┘
```

#### Detailed Component Breakdown

1. **`Search & Inline Registration Panel` (`QuickPatientDrawer.jsx`):**
   - Search Combobox with 300ms debounce querying `GET /api/v1/patients/search?query=`.
   - If not found: single-click "Register & Start Walk-in" button opens `QuickPatientDrawer` (Name, Phone, Age, Gender). Submitting immediately starts the encounter via `POST /api/v1/encounters/quick-start`.

2. **`DynamicVitalsForm.jsx`:**
   - Accepts `vitalsConfig` array from context.
   - Generates input fields dynamically with units (mmHg, bpm, °C, kg, etc.).
   - Computes real-time BMI if both `weight` and `height` exist.

3. **`Prescription Builder`:**
   - Embedded medication grid with drug autocomplete, frequency selectors, dosage, and duration.
   - Built-in validation before submission.

4. **`Auto-Save Mechanism` (`useEncounterDraft.js`):**
   - 15-second interval timer + debounced on-change trigger sending `PATCH /api/v1/encounters/{id}/draft`.
   - Maintains an `AbortController` instance. When the doctor clicks "Complete & Pay", pending draft HTTP requests are immediately aborted to prevent race-condition overwrite of a completed encounter.

5. **`PaymentCheckoutModal.jsx`:**
   - Displays invoice breakdown (Consultation + Extra billable services).
   - Allows choosing payment mode: `cash`, `visa`, or `split`.
   - Validates that entered amounts match invoice total.
   - Direct atomic submit calling `POST /api/v1/encounters/{id}/complete`.

6. **`TodayEncountersDrawer.jsx`:**
   - Slide-over drawer on the right side.
   - Lists today's encounters with badges (`completed`, `in_progress`).
   - Quick actions: "Reprint Prescription", "Print Receipt", or "Resume Draft".

---

## 4. Step-by-Step Execution Sequence & File Touch-List

### 4.1 Phased Execution Sequence

```
┌────────────────────────────────────────────────────────────────────────┐
│ Phase 1: Database & Migrations                                         │
│ 1. Modify clinic_settings migration (add clinic_mode, vitals_config).  │
│ 2. Create encounters migration.                                        │
│ 3. Clean appointments migration to pure scheduling fields.             │
│ 4. Decouple prescriptions and invoices to reference encounter_id.      │
│ 5. Execute `php artisan tenants:migrate`.                              │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Phase 2: Backend Domain & Enums                                        │
│ 1. Create PHP 8.1 Enums: ClinicMode, EncounterStatus, EncounterType.   │
│ 2. Create Encounter Model & update Prescription, Invoice, Patient,     │
│    Appointment, ClinicSetting with relationships & casts.              │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Phase 3: Backend Services & API Endpoints                              │
│ 1. Implement EncounterService (startWalkIn, saveDraft, complete, etc.).│
│ 2. Create EncounterController & Request validation classes.            │
│ 3. Update AuthController payload with clinic_mode & vitals_config.     │
│ 4. Register new routes in routes/tenant.php.                           │
│ 5. Write Pest feature test to verify full Solo Walk-in flow.           │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Phase 4: Frontend State & Architecture                                 │
│ 1. Update BranchContext to store and expose clinic_mode & vitals_config│
│ 2. Update roleUtils & AppRoutes for mode-aware conditional routing.    │
│ 3. Filter DashboardLayout sidebar links when in solo mode.             │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Phase 5: Solo Workspace UI & Components                                │
│ 1. Build QuickPatientDrawer for inline walk-in registration.           │
│ 2. Build DynamicVitalsForm driven by vitals_config.                    │
│ 3. Build PaymentCheckoutModal with cash/visa/split handling.           │
│ 4. Build TodayEncountersDrawer with reprint and summary view.          │
│ 5. Assemble full SoloWorkspaceView with 15s auto-save engine.          │
│ 6. End-to-end verification in both Solo and Polyclinic modes.          │
└────────────────────────────────────────────────────────────────────────┘
```

---

### 4.2 Comprehensive File Touch-List

#### Backend (`ServerSide`)

| Action | File Path | Purpose / Description |
|---|---|---|
| **Modify** | `database/migrations/tenant/2026_01_01_000005_create_tenant_clinic_settings_table.php` | Add `clinic_mode` enum/string and `vitals_config` JSON |
| **Modify** | `database/migrations/tenant/2026_01_01_000006_create_tenant_appointments_table.php` | Decouple clinical columns (`chief_complaint`, `diagnosis`, `vitals`, etc.) |
| **Create** | `database/migrations/tenant/2026_01_01_000008_create_tenant_encounters_table.php` | New `encounters` table schema with indexes and relationships |
| **Modify** | `database/migrations/tenant/2026_01_01_000009_create_tenant_prescriptions_table.php` | Add `encounter_id` and make `appointment_id` nullable |
| **Modify** | `database/migrations/tenant/2026_01_01_000013_create_tenant_invoices_table.php` | Add `encounter_id` index and relation |
| **Create** | `app/Enums/ClinicMode.php` | PHP 8.1 Enum (`SOLO = 'solo'`, `POLYCLINIC = 'polyclinic'`) |
| **Create** | `app/Enums/EncounterStatus.php` | PHP 8.1 Enum (`DRAFT`, `IN_PROGRESS`, `COMPLETED`, `CANCELLED`) |
| **Create** | `app/Enums/EncounterType.php` | PHP 8.1 Enum (`WALK_IN`, `CHECK_UP`, `FOLLOW_UP`, `EMERGENCY`) |
| **Create** | `app/Models/Encounter.php` | Eloquent Model with HasUuids, relations, and casts |
| **Modify** | `app/Models/ClinicSetting.php` | Add `clinic_mode` & `vitals_config` casts and fillable |
| **Modify** | `app/Models/Prescription.php` | Add `encounter_id` relation and fillable |
| **Modify** | `app/Models/Invoice.php` | Add `encounter_id` relation and fillable |
| **Modify** | `app/Models/Patient.php` | Add `encounters()` relation |
| **Modify** | `app/Models/Appointment.php` | Add `encounter()` relation |
| **Create** | `app/Services/Clinic/EncounterService.php` | Domain service handling walk-in, draft save, and checkout completion |
| **Create** | `app/Http/Controllers/Api/V1/Clinic/EncounterController.php` | API controller exposing quick-start, draft, complete, today-summary |
| **Create** | `app/Http/Requests/Api/V1/Encounter/QuickStartEncounterRequest.php` | Validation for walk-in start |
| **Create** | `app/Http/Requests/Api/V1/Encounter/SaveDraftEncounterRequest.php` | Validation for auto-save draft |
| **Create** | `app/Http/Requests/Api/V1/Encounter/CompleteEncounterRequest.php` | Validation for complete consultation + prescription + payment |
| **Create** | `app/Http/Resources/Api/V1/Encounter/EncounterResource.php` | API Resource for formatted encounter responses |
| **Modify** | `app/Http/Controllers/Api/V1/Auth/AuthController.php` | Inject `clinic_mode` and `vitals_config` into login and `/me` responses |
| **Modify** | `app/Http/Controllers/Api/V1/Clinic/PatientController.php` | Support `search?query=` alias and `medicalProfile` endpoint |
| **Modify** | `routes/tenant.php` | Register encounter endpoints and permissions |
| **Create** | `tests/Feature/SoloEncounterFlowTest.php` | Automated pest test verifying solo walk-in, prescription, and checkout |

#### Frontend (`ClientSide`)

| Action | File Path | Purpose / Description |
|---|---|---|
| **Modify** | `src/context/BranchContext.jsx` | Store `clinic_mode` and `vitals_config` and provide them via hook |
| **Modify** | `src/utils/roleUtils.js` | Helper to determine default route based on user role AND `clinic_mode` |
| **Modify** | `src/routes/AppRoutes.jsx` | Route `/doctor` to `SoloWorkspaceView` when `clinic_mode === 'solo'` |
| **Modify** | `src/layouts/DashboardLayout.jsx` | Conditionally filter navigation menu items based on `clinic_mode` |
| **Create** | `src/modules/clinical/api/encounterApi.js` | Axios/fetch client for encounter endpoints |
| **Create** | `src/modules/clinical/hooks/useEncounterDraft.js` | Auto-save hook with 15s interval, debounce, and `AbortController` |
| **Create** | `src/modules/clinical/components/QuickPatientDrawer.jsx` | Fast inline patient registration drawer |
| **Create** | `src/modules/clinical/components/DynamicVitalsForm.jsx` | Dynamic vitals inputs rendered according to `vitals_config` |
| **Create** | `src/modules/clinical/components/PaymentCheckoutModal.jsx` | Direct payment modal (Cash, Visa, Split) with immediate feedback |
| **Create** | `src/modules/clinical/components/TodayEncountersDrawer.jsx` | Slide-over drawer reviewing today's visits with reprint buttons |
| **Create** | `src/modules/clinical/pages/SoloWorkspaceView.jsx` | The unified Solo Doctor clinical workspace page |
