<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformTenantListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $domain = $this->relationLoaded('domains')
            ? $this->domains->first()?->domain
            : $this->domains()->first()?->domain;

        return [
            'id'             => $this->id,
            'clinic_name'    => $this->clinic_name ?? $this->id,
            'domain'         => $domain ?? ($this->id . '.my-saas.test'),
            'is_active'      => (bool) ($this->is_active ?? true),
            'branches_count' => (int) ($this->branches_count ?? $this->branches()->count()),
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
