<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Drug;
use App\Models\LiveQueue;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MedicalDataSeeder extends Seeder
{
    public function run(): void
    {
        // Truncate medical data for idempotent clean seeding
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        PrescriptionItem::truncate();
        Prescription::truncate();
        LiveQueue::truncate();
        Appointment::truncate();
        Patient::truncate();
        Drug::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // 1. Seed Global Drugs Catalog (Shared across all tenants)
        $drugsData = [
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

        $createdDrugs = [];
        foreach ($drugsData as $drugInfo) {
            $createdDrugs[] = Drug::create($drugInfo);
        }

        // 2. Seed Medical Data per Tenant
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($tenant, $createdDrugs) {
                $branches = Branch::all();
                if ($branches->isEmpty()) {
                    return;
                }

                $doctors = User::role('doctor')->get();
                $primaryDoctor = $doctors->first();

                $branchMaadi = $branches->first();
                $today = Carbon::today();

                // Create Patients
                $patientsData = [
                    [
                        'medical_number' => 'PT-2026-001',
                        'name' => 'أحمد محمود السيد',
                        'phone' => '01012345678',
                        'date_of_birth' => '1992-05-15',
                        'age' => 34,
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
                        'age' => 45,
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
                        'age' => 29,
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
                        'age' => 52,
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
                        'age' => 22,
                        'gender' => 'male',
                        'blood_group' => 'O-',
                        'chronic_diseases' => 'لا يوجد',
                        'allergies' => 'لا يوجد',
                        'surgeries' => 'لا يوجد',
                        'medical_history' => 'فحص لياقة بدنية ورياضية',
                    ],
                ];

                $createdPatients = [];
                foreach ($patientsData as $pData) {
                    $createdPatients[] = Patient::create($pData);
                }

                // Create Appointments, Live Queues & Prescriptions
                foreach ($createdPatients as $index => $patient) {
                    $apptTime = $today->copy()->addHours(9 + ($index * 2));
                    $docId = $primaryDoctor ? $primaryDoctor->id : null;

                    // 1. Appointment status
                    $status = $index === 0 ? 'completed' : ($index === 1 ? 'under_examination' : 'checked_in');

                    $appointment = Appointment::create([
                        'branch_id' => $branchMaadi->id,
                        'patient_id' => $patient->id,
                        'doctor_id' => $docId,
                        'appointment_time' => $apptTime,
                        'type' => 'check_up',
                        'status' => $status,
                        'chief_complaint' => 'صداع مستمر وزغللة في العين مع ارتفاع طفيف في درجة الحرارة',
                        'diagnosis' => 'إجهاد عام مع ارتفاع طفيف في ضغط الدم 130/85',
                        'clinical_examination' => 'فحص الصدر والبطن سليم، النبض منتظم 78 نبضة/دقيقة',
                        'blood_pressure' => '130/85',
                        'weight' => 78.50,
                        'temperature' => 37.2,
                        'started_at' => $apptTime,
                        'completed_at' => $status === 'completed' ? $apptTime->copy()->addMinutes(20) : null,
                    ]);

                    // 2. Live Queue status
                    $queueStatus = $status === 'completed' ? 'completed' : ($status === 'under_examination' ? 'under_examination' : 'checked_in');

                    LiveQueue::create([
                        'branch_id' => $branchMaadi->id,
                        'doctor_id' => $docId,
                        'patient_id' => $patient->id,
                        'appointment_id' => $appointment->id,
                        'shift_date' => $today,
                        'queue_no' => $index + 1,
                        'status' => $queueStatus,
                        'checked_in_at' => $apptTime->format('H:i:s'),
                    ]);

                    // 3. Prescription for completed/under_examination appointments
                    if (in_array($status, ['completed', 'under_examination']) && $docId) {
                        $prescription = Prescription::create([
                            'appointment_id' => $appointment->id,
                            'patient_id' => $patient->id,
                            'doctor_id' => $docId,
                            'prescription_code' => 'RX-' . strtoupper($tenant->id) . '-' . str_pad((string)($index + 1), 4, '0', STR_PAD_LEFT),
                            'prescription_date' => $today,
                            'general_advice' => 'الراحة التامة لمدة 3 أيام، الإكثار من تناول السوائل الدافئة، وتجنب الأطعمة المالحة.',
                            'follow_up_date' => $today->copy()->addDays(7),
                        ]);

                        // Add Prescription Items (Snapshots)
                        $drug1 = $createdDrugs[0] ?? null;
                        $drug2 = $createdDrugs[1] ?? null;

                        PrescriptionItem::create([
                            'prescription_id' => $prescription->id,
                            'drug_id' => $drug1 ? $drug1->id : null,
                            'drug_name' => $drug1 ? $drug1->trade_name : 'Panadol Extra 500mg',
                            'dose' => 'قرص واحد',
                            'frequency' => 'كل 8 ساعات عند الحاجة',
                            'duration' => '5 أيام',
                            'instruction' => 'بعد الأكل مع كوب ماء كبير',
                            'sort_order' => 1,
                        ]);

                        PrescriptionItem::create([
                            'prescription_id' => $prescription->id,
                            'drug_id' => $drug2 ? $drug2->id : null,
                            'drug_name' => $drug2 ? $drug2->trade_name : 'Augmentin 1g',
                            'dose' => 'قرص واحد',
                            'frequency' => 'كل 12 ساعة',
                            'duration' => '7 أيام',
                            'instruction' => 'في منتصف الوجبة لتقليل اضطراب المعدة',
                            'sort_order' => 2,
                        ]);
                    }
                }
            });
        }
    }
}
