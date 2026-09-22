<?php

namespace App\Http\Resources\Api\V1\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformTenantUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $createdAt = $this->formatted_created_at ?? null;
        if (!$createdAt) {
            try {
                $createdAt = $this->created_at?->toIso8601String();
            } catch (\Throwable) {
                $createdAt = is_string($this->getRawOriginal('created_at')) ? $this->getRawOriginal('created_at') : null;
            }
        }

        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'email'      => $this->email,
            'roles'      => $this->tenant_roles ?? [],
            'branches'   => $this->tenant_branches ?? [],
            'branch_ids' => $this->tenant_branch_ids ?? [],
            'created_at' => $createdAt,
        ];
    }
}
