<?php

namespace App\Http\Requests\Api\V1\Patient;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePatientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'             => 'sometimes|required|string|max:255',
            'phone'            => 'sometimes|required|string|max:255',
            'age'              => 'nullable|integer|min:0|max:150',
            'gender'           => 'nullable|in:male,female',
            'medical_number'   => 'nullable|string|max:100',
            'blood_group'      => 'nullable|string|max:10',
            'chronic_diseases' => 'nullable|string|max:1000',
            'allergies'        => 'nullable|string|max:1000',
            'surgeries'        => 'nullable|string|max:1000',
            'medical_history'  => 'nullable|string|max:2000',
        ];
    }
}
