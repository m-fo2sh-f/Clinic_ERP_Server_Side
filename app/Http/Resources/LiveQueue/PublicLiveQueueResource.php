<?php

namespace App\Http\Resources\LiveQueue;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicLiveQueueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // إخفاء باقي الاسم لحماية الخصوصية
        $fullName = $this->patient?->name ?? 'مريض';
        $parts = explode(' ', trim($fullName));
        $anonymizedName = count($parts) > 1 
            ? $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '.'
            : $parts[0];

        return [
            'id'           => $this->id,
            'queue_no'     => $this->queue_no,
            'patient_name' => $anonymizedName,
            'status'       => $this->status,
            'checked_in_at'=> $this->checked_in_at,
        ];
    }
}
