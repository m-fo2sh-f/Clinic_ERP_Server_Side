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
        return [
            'id'                           => $this->id,
            'name'                         => $this->name,
            'phone'                        => $this->phone,
            'age'                          => $this->age,
            'gender'                       => $this->gender,
            'medical_history'              => $this->medical_history,
            'total_completed_count'        => (int) ($this->total_completed_count ?? 0),
            'branch_completed_count'       => (int) ($this->branch_completed_count ?? 0),
            'completed_appointments_count' => (int) ($this->completed_appointments_count ?? $this->total_completed_count ?? 0),
            'created_at'                   => $this->created_at?->toIso8601String(),
        ];
    }
}
