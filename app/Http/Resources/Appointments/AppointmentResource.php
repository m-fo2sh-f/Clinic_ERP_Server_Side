<?php

namespace App\Http\Resources\Appointments;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Patients\PatientResource;
use App\Http\Resources\Prescriptions\PrescriptionResource;

class AppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Safe resolution for diagnosis array (handles native array cast & legacy double-encoded strings)
        $diagnosisArray = is_array($this->diagnosis)
            ? $this->diagnosis
            : (is_string($this->diagnosis) ? json_decode($this->diagnosis, true) : []);
        if (is_string($diagnosisArray)) {
            $diagnosisArray = json_decode($diagnosisArray, true) ?: [];
        }

        return [
            'id'                    => $this->id,
            'branch_id'             => $this->branch_id,
            'doctor_id'             => $this->doctor_id,
            'patient'               => new PatientResource($this->whenLoaded('patient', $this->patient)),
            'appointment_time'      => $this->appointment_time,
            'type'                  => $this->type,
            'status'                => $this->status,
            'chief_complaint'       => $this->chief_complaint,
            'diagnosis'             => $diagnosisArray,
            'clinical_examination'  => $this->clinical_examination,
            'vitals'                => $this->vitals,
            'started_at'            => $this->started_at?->toIso8601String(),
            'completed_at'          => $this->completed_at?->toIso8601String(),
            'branch_name'           => $this->whenLoaded('branch', fn () => $this->branch->name),
            'doctor'                => $this->whenLoaded('doctor', fn () => [
                'id'   => $this->doctor->id,
                'name' => $this->doctor->name,
            ]),
            'prescription'          => new PrescriptionResource($this->whenLoaded('prescription')),
        ];
    }
}
