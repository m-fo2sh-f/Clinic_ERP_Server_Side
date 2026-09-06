<?php

namespace App\Http\Resources\Patients;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Appointments\AppointmentResource;

class PatientHistoryResource extends JsonResource
{
    /**
     * Transform the patient with full medical history into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                           => $this->id,
            'medical_number'               => $this->medical_number,
            'name'                         => $this->name,
            'phone'                        => $this->phone,
            'date_of_birth'                => $this->date_of_birth?->toDateString(),
            'age'                          => $this->age,
            'gender'                       => $this->gender,
            'blood_group'                  => $this->blood_group,
            'chronic_diseases'             => $this->chronic_diseases,
            'allergies'                    => $this->allergies,
            'surgeries'                    => $this->surgeries,
            'medical_history'              => $this->medical_history,
            'total_completed_count'        => (int) ($this->total_completed_count ?? $this->completed_appointments_count ?? 0),
            'branch_completed_count'       => (int) ($this->branch_completed_count ?? 0),
            'completed_appointments_count' => (int) ($this->completed_appointments_count ?? 0),
            'appointments'                 => AppointmentResource::collection($this->whenLoaded('appointments')),
            'consultations'                => AppointmentResource::collection($this->whenLoaded('appointments')),
            'created_at'                   => $this->created_at?->toIso8601String(),
        ];
    }
}
