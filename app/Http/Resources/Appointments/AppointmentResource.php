<?php

namespace App\Http\Resources\Appointments;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Patients\PatientResource;

class AppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id"=> $this->id,
            "branch_id"=> $this->branch_id,
            "patient"=> new PatientResource($this->patient),
            "appointment_time"=> $this->appointment_time,
            "type"=> $this->type,
            "status"=> $this->status,

        ];
    }
}
