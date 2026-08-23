<?php

namespace App\Http\Resources\Prescriptions;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionResource extends JsonResource
{
    /**
     * Transform the prescription into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'prescription_code' => $this->prescription_code,
            'prescription_date' => $this->prescription_date?->toDateString(),
            'general_advice'    => $this->general_advice,
            'follow_up_date'    => $this->follow_up_date?->toDateString(),
            'doctor_name'       => $this->whenLoaded('doctor', fn () => $this->doctor->name),
            'items'             => PrescriptionItemResource::collection($this->whenLoaded('items')),
            'created_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}
