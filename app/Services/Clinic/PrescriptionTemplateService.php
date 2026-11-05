<?php

namespace App\Services\Clinic;

use App\Models\ClinicSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PrescriptionTemplateService
{
    public const DEFAULT_TEMPLATE = [
        'mode' => 'builtin',
        'paper_size' => 'A5',
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
    ];

    public function __construct(
        protected PrescriptionImageStorageService $storageService
    ) {}

    /**
     * Get the active template for a branch and user, considering doctor overrides.
     */
    public function getTemplateForBranchAndUser(string $branchId, ?User $user = null): array
    {
        $setting = ClinicSetting::where('branch_id', $branchId)->first();
        $branchTemplate = $setting?->prescription_template ?: self::DEFAULT_TEMPLATE;

        $isDoctorOverride = false;
        $resolvedTemplate = $branchTemplate;

        if ($user && ! empty($user->prescription_template)) {
            $isDoctorOverride = true;
            $resolvedTemplate = array_merge($branchTemplate, $user->prescription_template);
        }

        $resolvedTemplate['branch_id'] = $branchId;
        $resolvedTemplate['is_doctor_override'] = $isDoctorOverride;

        return $resolvedTemplate;
    }

    /**
     * Update the template for the branch or user override.
     */
    public function updateTemplate(string $branchId, User $user, array $data): array
    {
        $tenantId = tenant('id') ?? 'default';
        $doctorOverride = $data['doctor_override'] ?? null;
        $shouldClearImage = ! empty($data['clear_image'])
            || (($data['mode'] ?? '') !== 'custom_image' && ! empty($data['clear_image_on_mode_change']));

        // If explicitly reverting doctor override
        if ($doctorOverride === false && ! empty($user->prescription_template)) {
            $oldUserImage = $user->prescription_template['image_path'] ?? null;
            if ($oldUserImage) {
                $this->storageService->deleteImage($oldUserImage, $tenantId);
            }
            $user->prescription_template = null;
            $user->save();

            return $this->getTemplateForBranchAndUser($branchId, $user);
        }

        $saveToUser = false;
        if ($doctorOverride === true) {
            $saveToUser = true;
        } elseif ($doctorOverride === null && $user->hasRole('doctor') && ! $user->hasRole('clinic_owner')) {
            $saveToUser = true;
        }

        $allowedKeys = [
            'mode',
            'paper_size',
            'margins',
            'template_id',
            'body_layout',
            'font_size',
            'primary_color',
            'show_diagnosis',
            'show_vitals',
            'show_qr',
            'show_rx_code',
            'auto_print',
            'laser_contrast_opt',
            'header_data',
            'footer_data',
            'image_path',
        ];

        if ($saveToUser) {
            $current = $user->prescription_template ?: $this->getTemplateForBranchAndUser($branchId, $user);
            $oldImagePath = $current['image_path'] ?? null;
            $updated = array_intersect_key(array_merge($current, $data), array_flip($allowedKeys));

            if ($shouldClearImage) {
                if ($oldImagePath) {
                    $this->storageService->deleteImage($oldImagePath, $tenantId);
                }
                $updated['image_path'] = null;
            } else {
                // Preserve verified server-side path; do not allow arbitrary client hijacking
                $updated['image_path'] = $oldImagePath;
            }

            $user->prescription_template = $updated;
            $user->save();
        } else {
            $setting = ClinicSetting::firstOrCreate(
                ['branch_id' => $branchId],
                ['queue_strategy' => 'hybrid', 'avg_appointment_duration' => 15]
            );

            $current = $setting->prescription_template ?: self::DEFAULT_TEMPLATE;
            $oldImagePath = $current['image_path'] ?? null;
            $updated = array_intersect_key(array_merge($current, $data), array_flip($allowedKeys));

            if ($shouldClearImage) {
                if ($oldImagePath) {
                    $this->storageService->deleteImage($oldImagePath, $tenantId);
                }
                $updated['image_path'] = null;
            } else {
                // Preserve verified server-side path; do not allow arbitrary client hijacking
                $updated['image_path'] = $oldImagePath;
            }

            $setting->prescription_template = $updated;
            $setting->save();
        }

        return $this->getTemplateForBranchAndUser($branchId, $user);
    }

    /**
     * Upload an image with atomic replacement (zero orphaned files):
     * 1. Store new file
     * 2. Save DB reference with concurrency row locking
     * 3. On success, delete old file from disk
     * 4. On DB failure, rollback and delete newly uploaded file
     */
    public function uploadTemplateImage(string $branchId, User $user, UploadedFile $file, ?bool $doctorOverride = null): array
    {
        $saveToUser = false;
        if ($doctorOverride === true) {
            $saveToUser = true;
        } elseif ($doctorOverride === null && $user->hasRole('doctor') && ! $user->hasRole('clinic_owner')) {
            $saveToUser = true;
        }

        $tenantId = tenant('id') ?? 'default';

        // 1. Store new image
        $newStoredPath = $this->storageService->storeImage($file, $tenantId);
        $oldPath = null;

        try {
            DB::transaction(function () use ($branchId, $user, $saveToUser, $newStoredPath, &$oldPath) {
                if ($saveToUser) {
                    $lockedUser = User::where('id', $user->id)->lockForUpdate()->first() ?: $user;
                    $current = $lockedUser->prescription_template ?: $this->getTemplateForBranchAndUser($branchId, $lockedUser);
                    $oldPath = $current['image_path'] ?? null;

                    $current['image_path'] = $newStoredPath;
                    $current['mode'] = 'custom_image';

                    $lockedUser->prescription_template = $current;
                    $lockedUser->save();
                } else {
                    $setting = ClinicSetting::where('branch_id', $branchId)->lockForUpdate()->first();
                    if (! $setting) {
                        $setting = ClinicSetting::firstOrCreate(
                            ['branch_id' => $branchId],
                            ['queue_strategy' => 'hybrid', 'avg_appointment_duration' => 15]
                        );
                    }

                    $current = $setting->prescription_template ?: self::DEFAULT_TEMPLATE;
                    $oldPath = $current['image_path'] ?? null;

                    $current['image_path'] = $newStoredPath;
                    $current['mode'] = 'custom_image';

                    $setting->prescription_template = $current;
                    $setting->save();
                }
            });

            // 2. Atomic swap: delete old file from disk AFTER database commit succeeds
            if ($oldPath && $oldPath !== $newStoredPath) {
                $this->storageService->deleteImage($oldPath, $tenantId);
            }
        } catch (\Throwable $e) {
            // Rollback: if DB commit failed, remove the newly uploaded file to prevent orphan files
            $this->storageService->deleteImage($newStoredPath, $tenantId);
            Log::error('Failed to commit prescription template image to database: '.$e->getMessage());
            throw $e;
        }

        return $this->getTemplateForBranchAndUser($branchId, $user);
    }

    /**
     * Delete the uploaded template image and clear DB field.
     */
    public function deleteTemplateImage(string $branchId, User $user, ?bool $doctorOverride = null): array
    {
        $saveToUser = false;
        if ($doctorOverride === true) {
            $saveToUser = true;
        } elseif ($doctorOverride === null && $user->hasRole('doctor') && ! $user->hasRole('clinic_owner')) {
            $saveToUser = true;
        }

        $tenantId = tenant('id') ?? 'default';
        $oldPath = null;

        if ($saveToUser) {
            $current = $user->prescription_template ?: [];
            $oldPath = $current['image_path'] ?? null;

            $current['image_path'] = null;
            $user->prescription_template = $current;
            $user->save();
        } else {
            $setting = ClinicSetting::where('branch_id', $branchId)->first();
            $current = $setting?->prescription_template ?: [];
            $oldPath = $current['image_path'] ?? null;

            if ($setting) {
                $current['image_path'] = null;
                $setting->prescription_template = $current;
                $setting->save();
            }
        }

        if ($oldPath) {
            $this->storageService->deleteImage($oldPath, $tenantId);
        }

        return $this->getTemplateForBranchAndUser($branchId, $user);
    }

    /**
     * Stream or get path for serving the template image safely.
     */
    public function getImagePath(string $filename): string
    {
        $tenantId = tenant('id') ?? 'default';

        return $this->storageService->resolvePhysicalPath($filename, $tenantId);
    }
}
