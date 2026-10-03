<?php

namespace App\Http\Resources\Api\V1\LiveQueue;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicLiveQueueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // إخفاء باقي الاسم لحماية الخصوصية
        $fullName = trim((string) ($this->patient?->name ?? 'مريض'));
        $parts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY);
        $anonymizedName = count($parts) > 1 
            ? $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '.'
            : ($parts[0] ?? 'مريض');

        return [
            'id'           => $this->id,
            'queue_no'     => $this->queue_no,
            'patient_name' => $anonymizedName,
            'status'       => $this->status,
            'checked_in_at'=> $this->checked_in_at,
        ];
    }
}
