<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Clinic\DoctorDrug;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SearchDrugRequest extends FormRequest
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
        $rawQuery = (string) ($this->input('query') ?? $this->input('q') ?? '');
        $sanitizedQuery = trim(strip_tags($rawQuery));

        $this->merge([
            'query' => $sanitizedQuery,
            'limit' => $this->filled('limit') ? (int) $this->input('limit') : 15,
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
            'query' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
