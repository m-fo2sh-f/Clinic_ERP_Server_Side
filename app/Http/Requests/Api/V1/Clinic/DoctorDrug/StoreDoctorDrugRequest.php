<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Clinic\DoctorDrug;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDoctorDrugRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasRole(['doctor', 'clinic_owner']);
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'trade_name' => trim(strip_tags((string) $this->input('trade_name'))),
            'form' => $this->filled('form') ? trim(strip_tags((string) $this->input('form'))) : null,
            'strength' => $this->filled('strength') ? trim(strip_tags((string) $this->input('strength'))) : null,
            'active_ingredient' => $this->filled('active_ingredient') ? trim(strip_tags((string) $this->input('active_ingredient'))) : null,
            'default_dosage' => $this->filled('default_dosage') ? trim(strip_tags((string) $this->input('default_dosage'))) : null,
            'is_favorite' => $this->boolean('is_favorite'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'trade_name' => ['required', 'string', 'min:1', 'max:255'],
            'strength' => ['required', 'string', 'min:1', 'max:100'],
            'form' => ['required', 'string', 'min:1', 'max:100'],
            'default_dosage' => ['required', 'string', 'min:1', 'max:500'],
            'active_ingredient' => ['nullable', 'string', 'max:500'],
            'is_favorite' => ['nullable', 'boolean'],
        ];
    }
}
