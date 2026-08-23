<?php

namespace App\Http\Resources\Prescriptions;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionItemResource extends JsonResource
{
    /**
     * Transform a prescription line item into an array with unified field names.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'drug_id'      => $this->drug_id,
            'drug_name'    => $this->drug_name,
            'dose'         => $this->dose,
            'dosage'       => $this->dose,
            'frequency'    => $this->frequency,
            'duration'     => $this->duration,
            'instruction'  => $this->instruction,
            'instructions' => $this->instruction,
            'sort_order'   => $this->sort_order,
        ];
    }
}
