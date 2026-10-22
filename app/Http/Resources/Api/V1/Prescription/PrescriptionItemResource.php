<?php

namespace App\Http\Resources\Api\V1\Prescription;

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
            'id' => (string) $this->id,
            'drug_id' => $this->drug_id ? (string) $this->drug_id : null,
            'drug_name' => (string) $this->drug_name,
            'dosage' => (string) ($this->dosage ?? ''),
            'sort_order' => (int) ($this->sort_order ?? 0),
        ];
    }
}
