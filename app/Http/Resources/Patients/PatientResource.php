<?php

namespace App\Http\Resources\Patients;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isClinicalStaff = $request->user()?->hasAnyRole(['doctor', 'clinic_owner']);

        return [
            'id'                           => $this->id,
            'medical_number'               => $this->medical_number,
            'name'                         => $this->name,
            'phone'                        => $this->phone,
            'date_of_birth'                => $this->date_of_birth?->toDateString(),
            'age'                          => $this->age,
            'gender'                       => $this->gender,
            'blood_group'                  => $this->blood_group,
            'chronic_diseases'             => $this->when($isClinicalStaff, $this->chronic_diseases),
            'allergies'                    => $this->when($isClinicalStaff, $this->allergies),
            'surgeries'                    => $this->when($isClinicalStaff, $this->surgeries),
            'medical_history'              => $this->when($isClinicalStaff, $this->medical_history),
            'total_completed_count'        => (int) ($this->total_completed_count ?? 0),
            'branch_completed_count'       => (int) ($this->branch_completed_count ?? 0),
            'completed_appointments_count' => (int) ($this->completed_appointments_count ?? $this->total_completed_count ?? 0),
            'created_at'                   => $this->created_at?->toIso8601String(),
        ];
    }
}
