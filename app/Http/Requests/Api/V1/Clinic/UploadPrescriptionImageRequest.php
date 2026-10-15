<?php

namespace App\Http\Requests\Api\V1\Clinic;

use Illuminate\Foundation\Http\FormRequest;

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
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
            ],
            'file' => [
                'required_without:image',
                'file',
                'image',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
            ],
            'doctor_override' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'string'],
        ];
    }
}
