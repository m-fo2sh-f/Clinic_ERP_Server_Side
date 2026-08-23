<?php

namespace App\Http\Resources\Consultations;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Appointments\AppointmentResource;
use App\Http\Resources\Prescriptions\PrescriptionResource;

class ConsultationResource extends JsonResource
{
    /**
     * Transform the completed consultation result into an array.
     *
     * Expects the underlying resource to be an associative array with
     * 'appointment' and 'prescription' keys (returned by ConsultationService).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'appointment'   => new AppointmentResource($this['appointment']),
            'prescription'  => new PrescriptionResource($this['prescription']),
            'queue_status'  => 'completed',
            'completed_at'  => $this['appointment']->completed_at?->toIso8601String(),
        ];
    }
}
