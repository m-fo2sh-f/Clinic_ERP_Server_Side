<?php

declare(strict_types=1);

namespace App\Services\Clinic\Settings;

use App\Models\Branch;
use App\Models\ClinicSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class ClinicSettingsResolverService
{
    public const DEFAULT_PRESCRIPTION_TEMPLATE = [
        'layout_mode' => 'standard_flow', // 'standard_flow' | 'freeform_canvas'
        'mode' => 'builtin',              // 'builtin' | 'letterhead' | 'custom_image'
        'paper_size' => 'A5',             // 'A5' | 'A4'
        'template_id' => 'egyptian_clinic',
        'body_layout' => 'columns',
        'font_size' => 'md',
        'primary_color' => '#991b1b',
        'margins' => [
            'top_cm' => 4.5,
            'bottom_cm' => 3.0,
            'left_cm' => 1.5,
            'right_cm' => 1.5,
        ],
        'show_diagnosis' => true,
        'show_vitals' => true,
        'show_qr' => true,
        'show_rx_code' => true,
        'auto_print' => false,
        'laser_contrast_opt' => true,
        'header_data' => [
            'title' => 'المركز الطبي لأمراض القلب والباطنة',
            'specialty' => 'عيادات الباطنة والقلب التخصصية',
            'degrees' => 'زميل كلية الأطباء الملكية بلندن • دكتوراه أمراض القلب',
            'sub_specialties' => 'باطنة عامة — أمراض القلب — سكر وضغط — رسم قلب وإيكو',
            'second_doctor' => [
                'name' => 'دكتور / محمد وحيد سليمان',
                'title' => 'ماجستير أمراض القلب والأوعية الدموية',
                'degrees' => 'زمالة كهرومغناطيسية القلب ومنظمات ضربات القلب',
            ],
            'phones' => ['0402388422', '01026758170'],
            'address' => 'طنطا — ميدان المشحمة — شارع الجلاء — برج الأطباء',
        ],
        'footer_data' => [
            'services' => 'فحص القلب الفوري بجهاز الإيكو — رسم القلب الكهربائي — سونار على البطن والحوض — جهاز استنشاق بالموجات الصوتية',
            'clinic_phones' => '0402388422 — 01026758170',
            'doctor_phones' => '01227117011 — 01025591123',
            'address' => 'طنطا — ميدان المشحمة — شارع الجلاء — برج الأطباء',
        ],
        'image_path' => null,
        'guide_image_path' => null,
        'freeform_layout' => null,
    ];

    public const DEFAULT_VITALS_CONFIG = [
        'refraction_card_enabled' => false,
        'fields' => [
            [
                'key' => 'blood_pressure',
                'label' => 'ضغط الدم (BP)',
                'label_en' => 'Blood Pressure',
                'unit' => 'mmHg',
                'type' => 'text',
                'is_enabled' => true,
                'show_in_prescription' => true,
                'is_custom' => false,
            ],
            [
                'key' => 'heart_rate',
                'label' => 'نبض القلب (Pulse)',
                'label_en' => 'Heart Rate',
                'unit' => 'bpm',
                'type' => 'number',
                'is_enabled' => true,
                'show_in_prescription' => true,
                'is_custom' => false,
            ],
            [
                'key' => 'temperature',
                'label' => 'درجة الحرارة (Temp)',
                'label_en' => 'Temperature',
                'unit' => '°C',
                'type' => 'number',
                'is_enabled' => true,
                'show_in_prescription' => true,
                'is_custom' => false,
            ],
            [
                'key' => 'weight',
                'label' => 'الوزن (Weight)',
                'label_en' => 'Weight',
                'unit' => 'kg',
                'type' => 'number',
                'is_enabled' => true,
                'show_in_prescription' => true,
                'is_custom' => false,
            ],
            [
                'key' => 'height',
                'label' => 'الطول (Height)',
                'label_en' => 'Height',
                'unit' => 'cm',
                'type' => 'number',
                'is_enabled' => true,
                'show_in_prescription' => true,
                'is_custom' => false,
            ],
            [
                'key' => 'blood_sugar',
                'label' => 'السكر العشوائي (RBS)',
                'label_en' => 'Random Blood Sugar',
                'unit' => 'mg/dL',
                'type' => 'number',
                'is_enabled' => true,
                'show_in_prescription' => true,
                'is_custom' => false,
            ],
            [
                'key' => 'spo2',
                'label' => 'نسبة الأكسجين (SpO2)',
                'label_en' => 'Oxygen Saturation',
                'unit' => '%',
                'type' => 'number',
                'is_enabled' => true,
                'show_in_prescription' => true,
                'is_custom' => false,
            ],
        ],
    ];

    public function __construct(
        protected PrescriptionImageStorageService $storageService
    ) {}

    /**
     * Enforce strict IDOR prevention and resolve the verified target doctor ID.
     */
    public function resolveTargetDoctorId(User $user, ?string $requestedDoctorId): ?string
    {
        $isOwnerOrAdmin = $user->hasRole('clinic_owner') || (bool) $user->is_super_admin;

        // If regular doctor: strictly lock to their own ID
        if (! $isOwnerOrAdmin) {
            return (string) $user->id;
        }

        // Clinic owner can target a specific doctor or leave null for branch default
        return ($requestedDoctorId && trim($requestedDoctorId) !== '') ? trim($requestedDoctorId) : null;
    }

    /**
     * Resolve the active prescription template through hierarchical fallback.
     */
    public function resolvePrescriptionTemplate(string $branchId, ?string $targetDoctorId, User $user): array
    {
        $verifiedDoctorId = $this->resolveTargetDoctorId($user, $targetDoctorId);
        $branchDoctors = $this->getBranchDoctors($branchId);

        // 1. Try Doctor Custom Setting
        if ($verifiedDoctorId) {
            $doctorSetting = ClinicSetting::where('branch_id', $branchId)
                ->where('doctor_id', $verifiedDoctorId)
                ->first();

            if ($doctorSetting && ! empty($doctorSetting->prescription_template)) {
                $template = array_merge(self::DEFAULT_PRESCRIPTION_TEMPLATE, $doctorSetting->prescription_template);
                $template['branch_id'] = $branchId;
                $template['doctor_id'] = $verifiedDoctorId;
                $template['is_doctor_override'] = true;
                $template['branch_doctors'] = $branchDoctors;

                return $template;
            }
        }

        // 2. Try Branch Default Setting (whereNull doctor_id)
        $branchSetting = ClinicSetting::where('branch_id', $branchId)
            ->whereNull('doctor_id')
            ->first();

        if ($branchSetting && ! empty($branchSetting->prescription_template)) {
            $template = array_merge(self::DEFAULT_PRESCRIPTION_TEMPLATE, $branchSetting->prescription_template);
            $template['branch_id'] = $branchId;
            $template['doctor_id'] = $verifiedDoctorId;
            $template['is_doctor_override'] = false;
            $template['branch_doctors'] = $branchDoctors;

            return $template;
        }

        // 3. Fallback to System Default
        $template = self::DEFAULT_PRESCRIPTION_TEMPLATE;
        $template['branch_id'] = $branchId;
        $template['doctor_id'] = $verifiedDoctorId;
        $template['is_doctor_override'] = false;
        $template['branch_doctors'] = $branchDoctors;

        return $template;
    }

    /**
     * Resolve the active vitals config through hierarchical fallback.
     */
    public function resolveVitalsConfig(string $branchId, ?string $targetDoctorId, User $user): array
    {
        $verifiedDoctorId = $this->resolveTargetDoctorId($user, $targetDoctorId);
        $branchDoctors = $this->getBranchDoctors($branchId);

        // 1. Doctor Custom Setting
        if ($verifiedDoctorId) {
            $doctorSetting = ClinicSetting::where('branch_id', $branchId)
                ->where('doctor_id', $verifiedDoctorId)
                ->first();

            if ($doctorSetting && ! empty($doctorSetting->vitals_config)) {
                return [
                    'branch_id' => $branchId,
                    'doctor_id' => $verifiedDoctorId,
                    'is_doctor_override' => true,
                    'branch_doctors' => $branchDoctors,
                    'config' => $doctorSetting->vitals_config,
                ];
            }
        }

        // 2. Branch Default Setting
        $branchSetting = ClinicSetting::where('branch_id', $branchId)
            ->whereNull('doctor_id')
            ->first();

        if ($branchSetting && ! empty($branchSetting->vitals_config)) {
            return [
                'branch_id' => $branchId,
                'doctor_id' => $verifiedDoctorId,
                'is_doctor_override' => false,
                'branch_doctors' => $branchDoctors,
                'config' => $branchSetting->vitals_config,
            ];
        }

        // 3. System Default
        return [
            'branch_id' => $branchId,
            'doctor_id' => $verifiedDoctorId,
            'is_doctor_override' => false,
            'branch_doctors' => $branchDoctors,
            'config' => self::DEFAULT_VITALS_CONFIG,
        ];
    }

    /**
     * Save prescription template using explicit MySQL-safe queries (NO upsert).
     */
    public function savePrescriptionTemplate(string $branchId, ?string $targetDoctorId, User $user, array $data): array
    {
        $verifiedDoctorId = $this->resolveTargetDoctorId($user, $targetDoctorId);

        if (array_key_exists('doctor_override', $data) && $data['doctor_override'] === false) {
            if ($verifiedDoctorId) {
                ClinicSetting::where('branch_id', $branchId)->where('doctor_id', $verifiedDoctorId)->delete();
                $targetUser = User::find($verifiedDoctorId);
                if ($targetUser) {
                    $targetUser->update(['prescription_template' => null]);
                }
            }

            return $this->resolvePrescriptionTemplate($branchId, null, $user);
        }

        // Fetch existing setting or instantiate new one
        $query = ClinicSetting::where('branch_id', $branchId);
        $setting = $verifiedDoctorId
            ? $query->where('doctor_id', $verifiedDoctorId)->first()
            : $query->whereNull('doctor_id')->first();

        if (! $setting) {
            $setting = new ClinicSetting([
                'branch_id' => $branchId,
                'doctor_id' => $verifiedDoctorId,
                'queue_strategy' => 'hybrid',
            ]);
        }

        $currentTemplate = $setting->prescription_template ?: self::DEFAULT_PRESCRIPTION_TEMPLATE;
        $updatedTemplate = array_merge($currentTemplate, $data);

        // Preserve uploaded image paths if not explicitly cleared
        if (! empty($data['clear_image'])) {
            $oldPath = $currentTemplate['image_path'] ?? null;
            if ($oldPath) {
                $tenantId = tenant('id') ?? 'default';
                $this->storageService->deleteImage($oldPath, $tenantId);
            }
            $updatedTemplate['image_path'] = null;
            if (($updatedTemplate['mode'] ?? '') === 'custom_image') {
                $updatedTemplate['mode'] = 'builtin';
            }
        } elseif (empty($data['image_path']) && ! empty($currentTemplate['image_path'])) {
            $updatedTemplate['image_path'] = $currentTemplate['image_path'];
        }

        if (! empty($data['clear_guide_image'])) {
            $oldGuidePath = $currentTemplate['guide_image_path'] ?? null;
            if ($oldGuidePath) {
                $tenantId = tenant('id') ?? 'default';
                $this->storageService->deleteImage($oldGuidePath, $tenantId);
            }
            $updatedTemplate['guide_image_path'] = null;
        } elseif (empty($data['guide_image_path']) && ! empty($currentTemplate['guide_image_path'])) {
            $updatedTemplate['guide_image_path'] = $currentTemplate['guide_image_path'];
        }

        // Clean internal helper keys
        unset($updatedTemplate['branch_doctors'], $updatedTemplate['is_doctor_override']);

        $setting->prescription_template = $updatedTemplate;
        $setting->save();

        if ($verifiedDoctorId) {
            $targetUser = User::find($verifiedDoctorId);
            if ($targetUser) {
                $targetUser->update(['prescription_template' => $updatedTemplate]);
            }
        }

        return $this->resolvePrescriptionTemplate($branchId, $verifiedDoctorId, $user);
    }

    /**
     * Save vitals configuration using explicit MySQL-safe queries (NO upsert).
     */
    public function saveVitalsConfig(string $branchId, ?string $targetDoctorId, User $user, array $config): array
    {
        $verifiedDoctorId = $this->resolveTargetDoctorId($user, $targetDoctorId);

        $query = ClinicSetting::where('branch_id', $branchId);
        $setting = $verifiedDoctorId
            ? $query->where('doctor_id', $verifiedDoctorId)->first()
            : $query->whereNull('doctor_id')->first();

        if (! $setting) {
            $setting = new ClinicSetting([
                'branch_id' => $branchId,
                'doctor_id' => $verifiedDoctorId,
                'queue_strategy' => 'hybrid',
            ]);
        }

        $setting->vitals_config = $config;
        $setting->save();

        return $this->resolveVitalsConfig($branchId, $verifiedDoctorId, $user);
    }

    /**
     * Upload stationery or guide background image isolated per doctor or branch default.
     */
    public function uploadImage(string $branchId, ?string $targetDoctorId, User $user, UploadedFile $file, string $imageField = 'image_path'): array
    {
        $verifiedDoctorId = $this->resolveTargetDoctorId($user, $targetDoctorId);
        $tenantId = tenant('id') ?? 'default';

        $query = ClinicSetting::where('branch_id', $branchId);
        $setting = $verifiedDoctorId
            ? $query->where('doctor_id', $verifiedDoctorId)->first()
            : $query->whereNull('doctor_id')->first();

        if (! $setting) {
            $setting = new ClinicSetting([
                'branch_id' => $branchId,
                'doctor_id' => $verifiedDoctorId,
                'queue_strategy' => 'hybrid',
                'prescription_template' => self::DEFAULT_PRESCRIPTION_TEMPLATE,
            ]);
        }

        $currentTemplate = $setting->prescription_template ?: self::DEFAULT_PRESCRIPTION_TEMPLATE;

        // Delete old image if exists
        $oldPath = $currentTemplate[$imageField] ?? null;
        if ($oldPath) {
            $this->storageService->deleteImage($oldPath, $tenantId);
        }

        // Store new image with isolated subfolder
        $subFolder = $verifiedDoctorId ? "doctor_{$verifiedDoctorId}" : 'default';
        $storedPath = $this->storageService->storeImage($file, $tenantId, $branchId, $subFolder);

        $currentTemplate[$imageField] = $storedPath;
        if ($imageField === 'image_path') {
            $currentTemplate['mode'] = 'custom_image';
        }
        $setting->prescription_template = $currentTemplate;
        $setting->save();

        if ($verifiedDoctorId) {
            $targetUser = User::find($verifiedDoctorId);
            if ($targetUser) {
                $targetUser->update(['prescription_template' => $currentTemplate]);
            }
        }

        return $this->resolvePrescriptionTemplate($branchId, $verifiedDoctorId, $user);
    }

    /**
     * Delete stationery or guide background image safely without cross-doctor deletion.
     */
    public function deleteImage(string $branchId, ?string $targetDoctorId, User $user, string $imageField = 'image_path'): array
    {
        $verifiedDoctorId = $this->resolveTargetDoctorId($user, $targetDoctorId);
        $tenantId = tenant('id') ?? 'default';

        $query = ClinicSetting::where('branch_id', $branchId);
        $setting = $verifiedDoctorId
            ? $query->where('doctor_id', $verifiedDoctorId)->first()
            : $query->whereNull('doctor_id')->first();

        if ($setting && ! empty($setting->prescription_template)) {
            $template = $setting->prescription_template;
            $oldPath = $template[$imageField] ?? null;

            if ($oldPath) {
                $this->storageService->deleteImage($oldPath, $tenantId);
            }

            $template[$imageField] = null;
            if ($imageField === 'image_path' && ($template['mode'] ?? '') === 'custom_image') {
                $template['mode'] = 'builtin';
            }
            $setting->prescription_template = $template;
            $setting->save();

            if ($verifiedDoctorId) {
                $targetUser = User::find($verifiedDoctorId);
                if ($targetUser) {
                    $targetUser->update(['prescription_template' => $template]);
                }
            }
        }

        return $this->resolvePrescriptionTemplate($branchId, $verifiedDoctorId, $user);
    }

    /**
     * Fetch active doctors assigned to a branch.
     */
    public function getBranchDoctors(string $branchId): array
    {
        return User::whereHas('roles', function ($q) {
            $q->whereIn('name', ['doctor', 'clinic_owner']);
        })
            ->whereHas('branches', function ($q) use ($branchId) {
                $q->where('branches.id', $branchId);
            })
            ->select(['users.id', 'users.name'])
            ->get()
            ->map(fn ($doc) => ['id' => (string) $doc->id, 'name' => (string) $doc->name])
            ->values()
            ->all();
    }
}
