<?php

namespace App\Http\Requests\Api\V1\Encounter;

use Illuminate\Foundation\Http\FormRequest;

class QuickStartEncounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id'            => 'required|exists:branches,id',
            'patient_id'           => 'nullable|exists:patients,id',
            'appointment_id'       => 'nullable|exists:appointments,id',
            'name'                 => 'required_without:patient_id|nullable|string|max:255',
            'phone'                => 'required_without:patient_id|nullable|string|max:50',
            'age'                  => 'nullable|integer|min:0|max:150',
            'gender'               => 'nullable|in:male,female',
            'date_of_birth'        => 'nullable|date',
            'type'                 => 'nullable|string|in:walk_in,check_up,follow_up,emergency',
            'chief_complaint'      => 'nullable|string|max:2000',
            'clinical_examination' => 'nullable|string|max:2000',
            'diagnosis'            => 'nullable',
            'vitals'               => 'nullable|array',
            'private_notes'        => 'nullable|string|max:2000',
            'resume_existing'      => 'nullable|boolean',
        ];
    }
}
