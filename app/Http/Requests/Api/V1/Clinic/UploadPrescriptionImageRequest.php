<?php

namespace App\Http\Requests\Api\V1\Clinic;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UploadPrescriptionImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => [
                'required_without:file',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048', // 2 MB max
                'dimensions:min_width=300,min_height=300,max_width=4500,max_height=4500',
            ],
            'file' => [
                'required_without:image',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
                'dimensions:min_width=300,min_height=300,max_width=4500,max_height=4500',
            ],
            'doctor_override' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'string'],
        ];
    }

    /**
     * Deep inspection to prevent polyglot files, fake extensions, or scripts disguised as images.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $fieldName = $this->hasFile('image') ? 'image' : 'file';
            $uploadedFile = $this->file('image') ?: $this->file('file');

            if (! $uploadedFile || ! $uploadedFile->isValid()) {
                return;
            }

            $realPath = $uploadedFile->getRealPath();

            // 1. Verify magic bytes and dimensions with getimagesize()
            $imageInfo = @getimagesize($realPath);
            if ($imageInfo === false) {
                $validator->errors()->add($fieldName, 'الملف المرفوع ليس ملف صورة نقطية صالح أو تالف.');

                return;
            }

            // 2. Strict whitelist of detected image types & MIME
            $detectedMime = $imageInfo['mime'] ?? '';
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

            if (! in_array($detectedMime, $allowedMimes, true)) {
                $validator->errors()->add($fieldName, 'صيغة الصورة المكتشفة غير مدعومة. يُسمح فقط بصيغ JPG و PNG و WebP.');

                return;
            }

            // 3. Double-check image dimensions as defense-in-depth
            $width = $imageInfo[0] ?? 0;
            $height = $imageInfo[1] ?? 0;
            if ($width < 300 || $height < 300 || $width > 4500 || $height > 4500) {
                $validator->errors()->add($fieldName, 'أبعاد الصورة غير صالحة. يجب أن تتراوح بين 300x300 و 4500x4500 بكسل.');

                return;
            }

            // 4. Polyglot / Embedded script protection across the ENTIRE file
            $contents = @file_get_contents($realPath);
            if ($contents !== false) {
                $dangerousTokens = [
                    '<?php',
                    '<?=',
                    '<script',
                    '<html',
                    '<svg',
                    'eval(',
                    'base64_decode(',
                    '__halt_compiler',
                    'system(',
                    'passthru(',
                    'shell_exec(',
                    'exec(',
                    '<!doctype',
                    'onload=',
                    'onerror=',
                ];

                $lower = strtolower($contents);
                foreach ($dangerousTokens as $token) {
                    if (str_contains($lower, $token)) {
                        $validator->errors()->add($fieldName, 'تم رفض الملف لاحتوائه على كود برمجي غير مصرح به.');

                        return;
                    }
                }

                // Check for PHP short open tags (e.g. <? with space/newline)
                if (preg_match('/<\?(?:php|=|\s)/i', $contents)) {
                    $validator->errors()->add($fieldName, 'تم رفض الملف لاحتوائه على كود برمجي غير مصرح به.');

                    return;
                }

                // 5. Deep structural validation with GD to detect corrupted chunks / polyglots
                if (function_exists('imagecreatefromstring')) {
                    $gdImage = @imagecreatefromstring($contents);
                    if ($gdImage === false) {
                        $validator->errors()->add($fieldName, 'ملف الصورة تالف أو غير متوافق مع معايير الأمان المعتمدة.');

                        return;
                    }
                    if (is_resource($gdImage)) {
                        imagedestroy($gdImage);
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'image.required_without' => 'يجب إرفاق ملف صورة الروشتة.',
            'image.image' => 'يجب أن يكون الملف صورة حقيقية صالحة.',
            'image.mimes' => 'يُسمح فقط برفع الصور بصيغة JPG أو PNG أو WebP.',
            'image.mimetypes' => 'نوع الوسائط غير مسموح به. يرجى اختيار صورة صالحة.',
            'image.max' => 'الحد الأقصى لحجم صورة الروشتة هو 2 ميجابايت (2048 كيلوبايت).',
            'image.dimensions' => 'أبعاد الصورة غير مناسبة للطباعة. يجب أن تكون الأبعاد بين 300x300 بكسل كحد أدنى و 4500x4500 بكسل كحد أقصى.',
            'file.max' => 'الحد الأقصى لحجم صورة الروشتة هو 2 ميجابايت (2048 كيلوبايت).',
            'file.dimensions' => 'أبعاد الصورة غير مناسبة للطباعة. يجب أن تكون الأبعاد بين 300x300 بكسل كحد أدنى و 4500x4500 بكسل كحد أقصى.',
        ];
    }
}
