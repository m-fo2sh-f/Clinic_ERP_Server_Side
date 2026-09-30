<?php

namespace App\Http\Resources\Api\V1\Encounter;

use App\Http\Resources\Api\V1\Patient\PatientResource;
use App\Http\Resources\Api\V1\Prescription\PrescriptionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EncounterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'branch_id'            => $this->branch_id,
            'patient_id'           => $this->patient_id,
            'doctor_id'            => $this->doctor_id,
            'appointment_id'       => $this->appointment_id,
            'type'                 => $this->type?->value ?? $this->type,
            'status'               => $this->status?->value ?? $this->status,
            'chief_complaint'      => $this->chief_complaint,
            'clinical_examination' => $this->clinical_examination,
            'diagnosis'            => $this->diagnosis,
            'vitals'               => $this->vitals,
            'private_notes'        => $this->private_notes,
            'started_at'           => $this->started_at?->toISOString(),
            'completed_at'         => $this->completed_at?->toISOString(),
            'created_at'           => $this->created_at?->toISOString(),
            'patient'              => $this->whenLoaded('patient', fn () => new PatientResource($this->patient)),
            'prescription'         => $this->whenLoaded('prescription', fn () => [
                'id'                => $this->prescription->id,
                'prescription_code' => $this->prescription->prescription_code,
                'prescription_date' => $this->prescription->prescription_date,
                'general_advice'    => $this->prescription->general_advice,
                'follow_up_date'    => $this->prescription->follow_up_date,
                'items'             => $this->prescription->relationLoaded('items') ? $this->prescription->items : [],
            ]),
            'invoice'              => $this->whenLoaded('invoice', fn () => [
                'id'             => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'subtotal'       => (float) $this->invoice->subtotal,
                'discount'       => (float) $this->invoice->discount,
                'total'          => (float) $this->invoice->total,
                'payment_status' => $this->invoice->payment_status?->value ?? $this->invoice->payment_status,
                'paid_at'        => $this->invoice->paid_at?->toISOString(),
                'items'          => $this->invoice->relationLoaded('items') ? $this->invoice->items : [],
                'payments'       => $this->invoice->relationLoaded('payments') ? $this->invoice->payments : [],
            ]),
        ];
    }
}
