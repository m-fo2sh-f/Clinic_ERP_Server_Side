<?php

namespace App\Http\Requests\Api\V1\Encounter;

use Illuminate\Foundation\Http\FormRequest;

class CompleteEncounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'chief_complaint'                  => 'nullable|string|max:5000',
            'clinical_examination'             => 'nullable|string|max:5000',
            'diagnosis'                        => 'nullable',
            'vitals'                           => 'nullable|array',
            'private_notes'                    => 'nullable|string|max:5000',
            'general_advice'                   => 'nullable|string|max:2000',
            'follow_up_date'                   => 'nullable|date',
            // Patient updates
            'patient_updates'                  => 'nullable|array',
            'patient_updates.blood_group'      => 'nullable|string|max:10',
            'patient_updates.chronic_diseases' => 'nullable|string|max:1000',
            'patient_updates.allergies'        => 'nullable|string|max:1000',
            // Medications
            'medications'                      => 'nullable|array',
            'medications.*.drug_id'            => 'nullable|uuid',
            'medications.*.drug_name'          => 'required_with:medications|string|max:255',
            'medications.*.dose'               => 'nullable|string|max:255',
            'medications.*.frequency'          => 'nullable|string|max:255',
            'medications.*.duration'           => 'nullable|string|max:255',
            'medications.*.instruction'        => 'nullable|string|max:500',
            // Services & Checkout
            'services'                         => 'nullable|array',
            'services.*.service_id'            => 'required_with:services|uuid',
            'services.*.quantity'              => 'nullable|integer|min:1',
            'discount'                         => 'nullable|numeric|min:0',
            'payments'                         => 'nullable|array',
            'payments.*.method'                => 'required_with:payments|in:cash,visa,split',
            'payments.*.amount'                => 'required_with:payments|numeric|min:0.01',
            'payments.*.transaction_reference' => 'nullable|string|max:255',
        ];
    }
}
