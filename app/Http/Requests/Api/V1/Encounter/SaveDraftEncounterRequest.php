<?php

namespace App\Http\Requests\Api\V1\Encounter;

use Illuminate\Foundation\Http\FormRequest;

class SaveDraftEncounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'chief_complaint'      => 'nullable|string|max:5000',
            'clinical_examination' => 'nullable|string|max:5000',
            'diagnosis'            => 'nullable',
            'vitals'               => 'nullable|array',
            'private_notes'        => 'nullable|string|max:5000',
        ];
    }
}
