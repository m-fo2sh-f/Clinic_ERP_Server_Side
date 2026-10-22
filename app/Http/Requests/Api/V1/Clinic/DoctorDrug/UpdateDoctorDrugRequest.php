<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Clinic\DoctorDrug;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDoctorDrugRequest extends FormRequest
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
        $payload = [];

        if ($this->has('trade_name')) {
            $payload['trade_name'] = trim(strip_tags((string) $this->input('trade_name')));
        }
        if ($this->has('form')) {
            $payload['form'] = $this->filled('form') ? trim(strip_tags((string) $this->input('form'))) : null;
        }
        if ($this->has('strength')) {
            $payload['strength'] = $this->filled('strength') ? trim(strip_tags((string) $this->input('strength'))) : null;
        }
        if ($this->has('active_ingredient')) {
            $payload['active_ingredient'] = $this->filled('active_ingredient') ? trim(strip_tags((string) $this->input('active_ingredient'))) : null;
        }
        if ($this->has('default_dosage')) {
            $payload['default_dosage'] = $this->filled('default_dosage') ? trim(strip_tags((string) $this->input('default_dosage'))) : null;
        }
        if ($this->has('is_favorite')) {
            $payload['is_favorite'] = $this->boolean('is_favorite');
        }

        $this->merge($payload);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'trade_name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'form' => ['nullable', 'string', 'max:100'],
            'strength' => ['nullable', 'string', 'max:100'],
            'active_ingredient' => ['nullable', 'string', 'max:500'],
            'default_dosage' => ['nullable', 'string', 'max:500'],
            'is_favorite' => ['nullable', 'boolean'],
        ];
    }
}
