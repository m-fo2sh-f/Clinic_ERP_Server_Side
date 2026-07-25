<?php

namespace App\Http\Resources\LiveQueue;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Patients\PatientResource;

class LiveQueueResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id"           => $this->id,
            "queue_no"     => $this->queue_no,
            "status"       => $this->status,
            "checked_in_at"  => $this->checked_in_at,
            "appointment_id" => $this->appointment_id,
            "patient" => new PatientResource($this->whenLoaded('patient')),
        ];
    }
}
