<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Clinic\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVitalsConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doctor_id' => ['nullable', 'string'],
            'refraction_card_enabled' => ['sometimes', 'boolean'],
            'enable_refraction' => ['sometimes', 'boolean'],
            'enabled_keys' => ['sometimes', 'array'],
            'enabled_keys.*' => ['string', 'max:100'],
            'custom_vitals' => ['sometimes', 'array'],
            'custom_vitals.*.key' => ['required_with:custom_vitals', 'string', 'max:100'],
            'custom_vitals.*.label' => ['required_with:custom_vitals', 'string', 'max:255'],
            'custom_vitals.*.unit' => ['nullable', 'string', 'max:50'],
            'fields' => ['sometimes', 'array'],
            'fields.*.key' => ['required_with:fields', 'string', 'max:100'],
            'fields.*.label' => ['required_with:fields', 'string', 'max:255'],
            'fields.*.label_en' => ['nullable', 'string', 'max:255'],
            'fields.*.unit' => ['nullable', 'string', 'max:50'],
            'fields.*.type' => ['nullable', 'string', 'max:50'],
            'fields.*.is_enabled' => ['sometimes', 'boolean'],
            'fields.*.show_in_prescription' => ['sometimes', 'boolean'],
            'fields.*.is_custom' => ['sometimes', 'boolean'],
        ];
    }
}
