<?php

namespace App\Services\Clinic;

use App\Models\ClinicSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
            'phones' => ['0402388422', '01026758170'],
            'address' => 'طنطا — ميدان المشحمة — شارع الجلاء — برج الأطباء',
            'second_doctor' => [
                'name' => 'دكتور / محمد وحيد سليمان',
                'title' => 'ماجستير أمراض القلب والأوعية الدموية',
                'degrees' => 'زمالة كهرومغناطيسية القلب ومنظمات ضربات القلب',
            ],
        ],
        'footer_data' => [
            'services' => 'فحص القلب الفوري بجهاز الإيكو — رسم القلب الكهربائي — سونار على البطن والحوض — جهاز استنشاق بالموجات الصوتية',
            'clinic_phones' => '0402388422 — 01026758170',
            'doctor_phones' => '01227117011 — 01025591123',
            'address' => 'طنطا — ميدان المشحمة — شارع الجلاء — برج الأطباء',
        ],
        'image_path' => null,
    ];

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
        $doctorOverride = isset($data['doctor_override']) ? (bool) $data['doctor_override'] : null;

        // If the user wants to remove/reset their doctor override
        if ($doctorOverride === false && ! empty($user->prescription_template)) {
            $oldUserImage = $user->prescription_template['image_path'] ?? null;
            if (is_string($oldUserImage) && ! empty($oldUserImage) && Storage::disk('public')->exists($oldUserImage)) {
                Storage::disk('public')->delete($oldUserImage);
            }

            $user->prescription_template = null;
            $user->save();

            return $this->getTemplateForBranchAndUser($branchId, $user);
        }

        // Determine if we should save to user override
        $saveToUser = false;
        if ($doctorOverride === true) {
            $saveToUser = true;
        } elseif ($doctorOverride === null && $user->hasRole('doctor') && ! $user->hasRole('clinic_owner')) {
            $saveToUser = true;
        }

        // Clean up input data keys
        $cleanData = $data;
        unset($cleanData['doctor_override'], $cleanData['branch_id']);

        if ($saveToUser) {
            $current = $user->prescription_template ?: $this->getTemplateForBranchAndUser($branchId, $user);

            // Garbage collection: check if image is being replaced
            $oldImagePath = $current['image_path'] ?? null;
            $newImagePath = $cleanData['image_path'] ?? null;
            if (is_string($oldImagePath) && ! empty($oldImagePath) && is_string($newImagePath) && $oldImagePath !== $newImagePath) {
                if (Storage::disk('public')->exists($oldImagePath)) {
                    Storage::disk('public')->delete($oldImagePath);
                }
            }

            $updated = array_merge($current, $cleanData);
            $user->prescription_template = $updated;
            $user->save();
        } else {
            $setting = ClinicSetting::firstOrCreate(
                ['branch_id' => $branchId],
                ['queue_strategy' => 'hybrid', 'avg_appointment_duration' => 15]
            );

            $current = $setting->prescription_template ?: self::DEFAULT_TEMPLATE;

            // Garbage collection: check if image is being replaced
            $oldImagePath = $current['image_path'] ?? null;
            $newImagePath = $cleanData['image_path'] ?? null;
            if (is_string($oldImagePath) && ! empty($oldImagePath) && is_string($newImagePath) && $oldImagePath !== $newImagePath) {
                if (Storage::disk('public')->exists($oldImagePath)) {
                    Storage::disk('public')->delete($oldImagePath);
                }
            }

            $updated = array_merge($current, $cleanData);
            $setting->prescription_template = $updated;
            $setting->save();
        }

        return $this->getTemplateForBranchAndUser($branchId, $user);
    }

    /**
     * Upload an image for the template and clean up any existing image.
     */
    public function uploadTemplateImage(string $branchId, User $user, UploadedFile $file, ?bool $doctorOverride = null): array
    {
        $saveToUser = false;
        if ($doctorOverride === true) {
            $saveToUser = true;
        } elseif ($doctorOverride === null && $user->hasRole('doctor') && ! $user->hasRole('clinic_owner')) {
            $saveToUser = true;
        }

        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $filename = Str::uuid()->toString().'.'.$extension;

        // Save new image using Storage::disk('public')
        $storedPath = Storage::disk('public')->putFileAs('templates', $file, $filename);

        if ($saveToUser) {
            $current = $user->prescription_template ?: $this->getTemplateForBranchAndUser($branchId, $user);

            // Garbage collection: delete old image
            $oldPath = $current['image_path'] ?? null;
            if (is_string($oldPath) && ! empty($oldPath) && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }

            $current['image_path'] = $storedPath;
            if (($current['mode'] ?? 'builtin') === 'builtin') {
                $current['mode'] = 'custom_image';
            }

            $user->prescription_template = $current;
            $user->save();
        } else {
            $setting = ClinicSetting::firstOrCreate(
                ['branch_id' => $branchId],
                ['queue_strategy' => 'hybrid', 'avg_appointment_duration' => 15]
            );

            $current = $setting->prescription_template ?: self::DEFAULT_TEMPLATE;

            // Garbage collection: delete old image
            $oldPath = $current['image_path'] ?? null;
            if (is_string($oldPath) && ! empty($oldPath) && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }

            $current['image_path'] = $storedPath;
            if (($current['mode'] ?? 'builtin') === 'builtin') {
                $current['mode'] = 'custom_image';
            }

            $setting->prescription_template = $current;
            $setting->save();
        }

        return $this->getTemplateForBranchAndUser($branchId, $user);
    }

    /**
     * Delete the uploaded template image.
     */
    public function deleteTemplateImage(string $branchId, User $user, ?bool $doctorOverride = null): array
    {
        $saveToUser = false;
        if ($doctorOverride === true) {
            $saveToUser = true;
        } elseif ($doctorOverride === null && $user->hasRole('doctor') && ! $user->hasRole('clinic_owner')) {
            $saveToUser = true;
        }

        if ($saveToUser) {
            $current = $user->prescription_template ?: [];
            $oldPath = $current['image_path'] ?? null;
            if (is_string($oldPath) && ! empty($oldPath) && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }

            $current['image_path'] = null;
            $user->prescription_template = $current;
            $user->save();
        } else {
            $setting = ClinicSetting::where('branch_id', $branchId)->first();
            $current = $setting?->prescription_template ?: [];
            $oldPath = $current['image_path'] ?? null;
            if (is_string($oldPath) && ! empty($oldPath) && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }

            if ($setting) {
                $current['image_path'] = null;
                $setting->prescription_template = $current;
                $setting->save();
            }
        }

        return $this->getTemplateForBranchAndUser($branchId, $user);
    }

    /**
     * Resolve the storage path for serving the template image.
     */
    public function getImagePath(string $filename): string
    {
        $cleanFilename = basename($filename);
        $relativePath = 'templates/'.$cleanFilename;

        if (! Storage::disk('public')->exists($relativePath)) {
            abort(404, 'Template image not found.');
        }

        return Storage::disk('public')->path($relativePath);
    }
}
