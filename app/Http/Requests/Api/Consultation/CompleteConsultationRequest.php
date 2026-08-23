<?php

namespace App\Http\Requests\Api\Consultation;

use Illuminate\Foundation\Http\FormRequest;

class CompleteConsultationRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller's authorizeBranchAccess().
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for the complete consultation payload.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Identity & context
            'live_queue_id'              => 'required|uuid|exists:live_queues,id',
            'appointment_id'             => 'required|uuid|exists:appointments,id',
            'patient_id'                 => 'required|uuid|exists:patients,id',
            'branch_id'                  => 'required|uuid|exists:branches,id',

            // Clinical notes & vitals JSON (Single Source of Truth)
            'chief_complaint'            => 'required|string|max:2000',
            'examination_findings'       => 'nullable|string|max:2000',
            'vitals'                     => 'nullable|array',
            'vitals.blood_pressure'      => 'nullable|string|max:50',
            'vitals.heart_rate'          => 'nullable|string|max:50',
            'vitals.temperature'         => 'nullable|string|max:50',
            'vitals.weight'              => 'nullable|string|max:50',
            'vitals.height'              => 'nullable|string|max:50',
            'vitals.spo2'                => 'nullable|string|max:50',
            'vitals.blood_sugar'         => 'nullable|string|max:50',

            // Patient demographic updates
            'patient_updates'                  => 'nullable|array',
            'patient_updates.blood_group'      => 'nullable|string|max:10',
            'patient_updates.chronic_diseases' => 'nullable|string|max:1000',
            'patient_updates.allergies'        => 'nullable|string|max:1000',

            // Diagnoses (at least one required)
            'diagnoses'                  => 'required|array|min:1',
            'diagnoses.*'                => 'required|string|max:255',

            // Medications (optional array of line items)
            'medications'                => 'nullable|array',
            'medications.*.name'         => 'required_with:medications|string|max:255',
            'medications.*.drug_id'      => 'nullable|uuid|exists:drugs,id',
            'medications.*.dosage'       => 'nullable|string|max:100',
            'medications.*.dose'         => 'nullable|string|max:100',
            'medications.*.frequency'    => 'nullable|string|max:100',
            'medications.*.duration'     => 'nullable|string|max:100',
            'medications.*.instructions' => 'nullable|string|max:500',
            'medications.*.instruction'  => 'nullable|string|max:500',
            'medications.*.sort_order'   => 'nullable|integer',

            // Advice & follow-up
            'general_advice'             => 'nullable|string|max:2000',
            'follow_up_date'             => 'nullable|date',
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'live_queue_id.required'     => 'Queue record ID is required.',
            'appointment_id.required'    => 'Appointment ID is required.',
            'patient_id.required'        => 'Patient ID is required.',
            'branch_id.required'         => 'Branch ID is required.',
            'chief_complaint.required'   => 'Chief complaint is required.',
            'diagnoses.required'         => 'At least one diagnosis is required.',
            'diagnoses.min'              => 'At least one diagnosis is required.',
            'medications.*.name.required_with' => 'Medication name is required.',
        ];
    }
}
