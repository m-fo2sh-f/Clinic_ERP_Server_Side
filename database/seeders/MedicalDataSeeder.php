<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EncounterStatus;
use App\Enums\EncounterType;
use App\Enums\LiveQueueStatus;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Drug;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LiveQueue;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MedicalDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();

        $drugsCatalog = [
            [
                'trade_name' => 'Panadol Extra',
                'active_ingredient' => 'Paracetamol 500mg / Caffeine 65mg',
                'form' => 'Tablet',
                'strength' => '500mg/65mg',
                'company' => 'GSK',
                'price' => 35.00,
                'therapeutic_class' => 'Analgesic & Antipyretic',
                'barcode' => '6221234567890',
            ],
            [
                'trade_name' => 'Augmentin 1g',
                'active_ingredient' => 'Amoxicillin 875mg / Clavulanic Acid 125mg',
                'form' => 'Tablet',
                'strength' => '1g',
                'company' => 'GSK',
                'price' => 120.00,
                'therapeutic_class' => 'Broad-Spectrum Antibiotic',
                'barcode' => '6221234567891',
            ],
            [
                'trade_name' => 'Concor 5mg',
                'active_ingredient' => 'Bisoprolol Fumarate',
                'form' => 'Tablet',
                'strength' => '5mg',
                'company' => 'Merck',
                'price' => 60.00,
                'therapeutic_class' => 'Antihypertensive (Beta Blocker)',
                'barcode' => '6221234567892',
            ],
            [
                'trade_name' => 'Cataflam 50mg',
                'active_ingredient' => 'Diclofenac Potassium',
                'form' => 'Tablet',
                'strength' => '50mg',
                'company' => 'Novartis',
                'price' => 55.00,
                'therapeutic_class' => 'NSAID Analgesic',
                'barcode' => '6221234567895',
            ],
            [
                'trade_name' => 'Nexium 40mg',
                'active_ingredient' => 'Esomeprazole Magnesium',
                'form' => 'Tablet',
                'strength' => '40mg',
                'company' => 'AstraZeneca',
                'price' => 145.00,
                'therapeutic_class' => 'Proton Pump Inhibitor (PPI)',
                'barcode' => '6221234567896',
            ],
            [
                'trade_name' => 'Brufen 100mg/5ml Syrup',
                'active_ingredient' => 'Ibuprofen',
                'form' => 'Syrup',
                'strength' => '100mg/5ml',
                'company' => 'Abbott',
                'price' => 40.00,
                'therapeutic_class' => 'NSAID & Anti-inflammatory',
                'barcode' => '6221234567894',
            ],
            [
                'trade_name' => 'Ventolin Inhaler',
                'active_ingredient' => 'Salbutamol Sulfate',
                'form' => 'Inhaler',
                'strength' => '100mcg/dose',
                'company' => 'GSK',
                'price' => 75.00,
                'therapeutic_class' => 'Bronchodilator',
                'barcode' => '6221234567897',
            ],
        ];

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($tenant, $drugsCatalog) {
                // Safeguard: Do NOT touch users, roles, permissions, or branch settings!
                DB::statement('SET FOREIGN_KEY_CHECKS=0;');
                Payment::truncate();
                InvoiceItem::truncate();
                Invoice::truncate();
                PrescriptionItem::truncate();
                Prescription::truncate();
                LiveQueue::truncate();
                Encounter::truncate();
                Appointment::truncate();
                Patient::truncate();
                Drug::truncate();
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');

                // 1. Seed Drugs
                $createdDrugs = [];
                foreach ($drugsCatalog as $drugData) {
                    $createdDrugs[] = Drug::firstOrCreate(['barcode' => $drugData['barcode']], $drugData);
                }

                // 2. Identify Branch & Doctor
                $branch = Branch::first();
                if (! $branch) {
                    return;
                }

                $doctor = User::role('doctor')->first() ?? User::where('email', 'like', 'dr.%')->first();
                if (! $doctor) {
                    return;
                }

                $today = Carbon::today();

                // 3. Seed Realistic Patients
                $patientsData = [
                    [
                        'medical_number' => 'PT-2026-001',
                        'name' => 'أحمد محمود السيد',
                        'phone' => '01012345678',
                        'date_of_birth' => '1992-05-15',
                        'gender' => 'male',
                        'blood_group' => 'A+',
                        'chronic_diseases' => 'السكري النوع الثاني',
                        'allergies' => 'حساسية البنسلين',
                        'surgeries' => 'استئصال الزائدة الدودية 2018',
                        'medical_history' => 'متابعة دورية للسكر وضغط الدم',
                    ],
                    [
                        'medical_number' => 'PT-2026-002',
                        'name' => 'محمد السيد عبد الله',
                        'phone' => '01123456789',
                        'date_of_birth' => '1981-08-20',
                        'gender' => 'male',
                        'blood_group' => 'O+',
                        'chronic_diseases' => 'ارتفاع ضغط الدم',
                        'allergies' => 'لا يوجد',
                        'surgeries' => 'استئصال اللوزتين',
                        'medical_history' => 'فحوصات سنوية منتظمة',
                    ],
                    [
                        'medical_number' => 'PT-2026-003',
                        'name' => 'مريم خالد إبراهيم',
                        'phone' => '01234567890',
                        'date_of_birth' => '1997-11-03',
                        'gender' => 'female',
                        'blood_group' => 'B+',
                        'chronic_diseases' => 'ربو شعبي خفيف',
                        'allergies' => 'حساسية السلفا والأناناس',
                        'surgeries' => 'لا يوجد',
                        'medical_history' => 'أزمات تنفسية موسمية عند تغير الفصول',
                    ],
                    [
                        'medical_number' => 'PT-2026-004',
                        'name' => 'فاطمة إبراهيم مصطفى',
                        'phone' => '01545678901',
                        'date_of_birth' => '1974-03-12',
                        'gender' => 'female',
                        'blood_group' => 'AB+',
                        'chronic_diseases' => 'ضغط دم مرتفع + سكري',
                        'allergies' => 'لا يوجد',
                        'surgeries' => 'جراحة منظار ركبة 2021',
                        'medical_history' => 'تتبع نظام غذائي منخفض الصوديوم',
                    ],
                    [
                        'medical_number' => 'PT-2026-005',
                        'name' => 'عمر فاروق الشريف',
                        'phone' => '01098765432',
                        'date_of_birth' => '2004-09-25',
                        'gender' => 'male',
                        'blood_group' => 'O-',
                        'chronic_diseases' => 'لا يوجد',
                        'allergies' => 'لا يوجد',
                        'surgeries' => 'لا يوجد',
                        'medical_history' => 'فحص لياقة بدنية ورياضية',
                    ],
                ];

                $patients = [];
                foreach ($patientsData as $pData) {
                    $patients[] = Patient::firstOrCreate(['medical_number' => $pData['medical_number']], $pData);
                }

                // Retrieve Consultation Service
                $consultService = Service::where('is_active', true)->first();

                // 4. Seed Live Queue Items in Various Lifecycle Stages

                // Stage A: checked_in (Walk-in, waiting in reception queue)
                LiveQueue::create([
                    'branch_id' => $branch->id,
                    'doctor_id' => $doctor->id,
                    'patient_id' => $patients[0]->id,
                    'appointment_id' => null,
                    'encounter_id' => null,
                    'shift_date' => $today,
                    'queue_no' => 1,
                    'status' => LiveQueueStatus::CHECKED_IN,
                    'checked_in_at' => Carbon::now()->subMinutes(30)->format('H:i:s'),
                ]);

                // Stage B: under_examination (Doctor clicked Next, encounter in progress)
                $encounterActive = Encounter::create([
                    'branch_id' => $branch->id,
                    'patient_id' => $patients[1]->id,
                    'doctor_id' => $doctor->id,
                    'appointment_id' => null,
                    'type' => EncounterType::CHECK_UP,
                    'status' => EncounterStatus::IN_PROGRESS,
                    'chief_complaint' => 'صداع متكرر وزغللة وإجهاد بدني عام',
                    'clinical_examination' => 'ضغط الدم مرتفع قليلا، قاع العين سليم، نبض منتظم',
                    'diagnosis' => ['مقدمات ارتفاع ضغط الدم (Prehypertension)'],
                    'vitals' => [
                        'bp_systolic' => '135',
                        'bp_diastolic' => '88',
                        'heart_rate' => '82',
                        'temperature' => '36.8',
                        'spo2' => '99',
                        'weight' => '84',
                        'height' => '178',
                    ],
                    'started_at' => Carbon::now()->subMinutes(12),
                ]);

                LiveQueue::create([
                    'branch_id' => $branch->id,
                    'doctor_id' => $doctor->id,
                    'patient_id' => $patients[1]->id,
                    'appointment_id' => null,
                    'encounter_id' => $encounterActive->id,
                    'shift_date' => $today,
                    'queue_no' => 2,
                    'status' => LiveQueueStatus::UNDER_EXAMINATION,
                    'checked_in_at' => Carbon::now()->subMinutes(25)->format('H:i:s'),
                ]);

                // Stage C: pending_payment (Doctor completed encounter, awaiting reception checkout)
                $encounterPendingPay = Encounter::create([
                    'branch_id' => $branch->id,
                    'patient_id' => $patients[2]->id,
                    'doctor_id' => $doctor->id,
                    'appointment_id' => null,
                    'type' => EncounterType::CHECK_UP,
                    'status' => EncounterStatus::COMPLETED,
                    'chief_complaint' => 'ألم حاد بالحلق وسعال جاف',
                    'clinical_examination' => 'احتقان بالبلعوم وتضخم خفيف بالغدد الليمفاوية العنقية',
                    'diagnosis' => ['التهاب الحلق واللوزتين الحاد (Acute Pharyngitis)'],
                    'vitals' => [
                        'bp_systolic' => '120',
                        'bp_diastolic' => '80',
                        'heart_rate' => '76',
                        'temperature' => '38.2',
                        'spo2' => '98',
                        'weight' => '65',
                        'height' => '162',
                    ],
                    'started_at' => Carbon::now()->subMinutes(45),
                    'completed_at' => Carbon::now()->subMinutes(20),
                ]);

                $fee = $consultService ? (float) $consultService->default_price : 200.00;
                $invPending = Invoice::create([
                    'invoice_number' => 'INV-'.strtoupper(Str::random(8)),
                    'encounter_id' => $encounterPendingPay->id,
                    'appointment_id' => null,
                    'patient_id' => $patients[2]->id,
                    'branch_id' => $branch->id,
                    'subtotal' => $fee,
                    'discount' => 0.00,
                    'total' => $fee,
                    'payment_status' => PaymentStatus::UNPAID,
                    'notes' => 'كشف استشاري',
                ]);

                InvoiceItem::create([
                    'invoice_id' => $invPending->id,
                    'service_id' => $consultService?->id,
                    'item_name' => $consultService?->name ?? 'كشف استشاري',
                    'unit_price' => $fee,
                    'quantity' => 1,
                    'total' => $fee,
                ]);

                LiveQueue::create([
                    'branch_id' => $branch->id,
                    'doctor_id' => $doctor->id,
                    'patient_id' => $patients[2]->id,
                    'appointment_id' => null,
                    'encounter_id' => $encounterPendingPay->id,
                    'shift_date' => $today,
                    'queue_no' => 3,
                    'status' => LiveQueueStatus::PENDING_PAYMENT,
                    'checked_in_at' => Carbon::now()->subMinutes(50)->format('H:i:s'),
                ]);

                // Stage D: completed (Examination finished, invoice paid, prescription issued)
                $encounterCompleted = Encounter::create([
                    'branch_id' => $branch->id,
                    'patient_id' => $patients[3]->id,
                    'doctor_id' => $doctor->id,
                    'appointment_id' => null,
                    'type' => EncounterType::FOLLOW_UP,
                    'status' => EncounterStatus::COMPLETED,
                    'chief_complaint' => 'متابعة أسبوعية لنتائج التحاليل ونسبة السكر',
                    'clinical_examination' => 'حالة مستقرة وتحسن ملحوظ بالأعراض السابقة',
                    'diagnosis' => ['متابعة السكري والضغط الدورية'],
                    'vitals' => [
                        'bp_systolic' => '125',
                        'bp_diastolic' => '82',
                        'heart_rate' => '72',
                        'temperature' => '36.7',
                        'spo2' => '99',
                        'weight' => '71',
                        'height' => '170',
                    ],
                    'started_at' => Carbon::now()->subHours(2),
                    'completed_at' => Carbon::now()->subHours(1)->subMinutes(40),
                ]);

                $invPaid = Invoice::create([
                    'invoice_number' => 'INV-'.strtoupper(Str::random(8)),
                    'encounter_id' => $encounterCompleted->id,
                    'appointment_id' => null,
                    'patient_id' => $patients[3]->id,
                    'branch_id' => $branch->id,
                    'subtotal' => $fee,
                    'discount' => 0.00,
                    'total' => $fee,
                    'payment_status' => PaymentStatus::PAID,
                    'paid_at' => Carbon::now()->subHours(1)->subMinutes(35),
                    'notes' => 'سداد نقدي عند الاستقبال',
                ]);

                InvoiceItem::create([
                    'invoice_id' => $invPaid->id,
                    'service_id' => $consultService?->id,
                    'item_name' => $consultService?->name ?? 'كشف استشاري',
                    'unit_price' => $fee,
                    'quantity' => 1,
                    'total' => $fee,
                ]);

                Payment::create([
                    'invoice_id' => $invPaid->id,
                    'cashier_id' => $doctor->id,
                    'amount' => $fee,
                    'payment_method' => 'cash',
                    'transaction_reference' => 'CASH-'.strtoupper(Str::random(6)),
                    'paid_at' => Carbon::now()->subHours(1)->subMinutes(35),
                ]);

                $rx = Prescription::create([
                    'encounter_id' => $encounterCompleted->id,
                    'appointment_id' => null,
                    'patient_id' => $patients[3]->id,
                    'doctor_id' => $doctor->id,
                    'prescription_code' => 'RX-'.strtoupper($tenant->id).'-'.str_pad((string) rand(100, 999), 4, '0', STR_PAD_LEFT),
                    'prescription_date' => $today,
                    'general_advice' => 'الاستمرار على العلاج الدوائي مع تقليل الملح والنشويات.',
                    'follow_up_date' => $today->copy()->addDays(14),
                ]);

                if (! empty($createdDrugs)) {
                    PrescriptionItem::create([
                        'prescription_id' => $rx->id,
                        'drug_id' => $createdDrugs[0]->id,
                        'drug_name' => $createdDrugs[0]->trade_name,
                        'dose' => 'قرص واحد',
                        'frequency' => 'كل 12 ساعة',
                        'duration' => '7 أيام',
                        'instruction' => 'بعد الأكل',
                        'sort_order' => 1,
                    ]);
                }

                LiveQueue::create([
                    'branch_id' => $branch->id,
                    'doctor_id' => $doctor->id,
                    'patient_id' => $patients[3]->id,
                    'appointment_id' => null,
                    'encounter_id' => $encounterCompleted->id,
                    'shift_date' => $today,
                    'queue_no' => 4,
                    'status' => LiveQueueStatus::COMPLETED,
                    'checked_in_at' => Carbon::now()->subHours(2)->subMinutes(10)->format('H:i:s'),
                ]);

                // Stage E: cancelled (Walk-away or abandoned queue item)
                LiveQueue::create([
                    'branch_id' => $branch->id,
                    'doctor_id' => $doctor->id,
                    'patient_id' => $patients[4]->id,
                    'appointment_id' => null,
                    'encounter_id' => null,
                    'shift_date' => $today,
                    'queue_no' => 5,
                    'status' => LiveQueueStatus::CANCELLED,
                    'checked_in_at' => Carbon::now()->subMinutes(90)->format('H:i:s'),
                ]);
            });
        }
    }
}
