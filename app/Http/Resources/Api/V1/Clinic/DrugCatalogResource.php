<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Clinic;

use App\Models\CentralDrug;
use App\Models\DoctorDrug;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DrugCatalogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof DoctorDrug) {
            return [
                'id' => (string) $this->resource->id,
                'trade_name' => (string) $this->resource->trade_name,
                'form' => $this->resource->form,
                'strength' => $this->resource->strength,
                'active_ingredient' => $this->resource->active_ingredient,
                'default_dosage' => $this->resource->default_dosage,
                'usage_count' => (int) $this->resource->usage_count,
                'is_favorite' => (bool) $this->resource->is_favorite,
                'is_doctor_favorite' => (bool) $this->resource->is_favorite,
                'is_custom' => (bool) $this->resource->is_custom,
                'source' => 'doctor_catalog',
                'created_at' => $this->resource->created_at?->toIso8601String(),
                'updated_at' => $this->resource->updated_at?->toIso8601String(),
            ];
        }

        if ($this->resource instanceof CentralDrug) {
            return [
                'id' => (string) $this->resource->id,
                'trade_name' => (string) $this->resource->trade_name,
                'form' => $this->resource->form,
                'strength' => $this->resource->strength,
                'active_ingredient' => $this->resource->active_ingredient,
                'default_dosage' => null,
                'usage_count' => 0,
                'is_favorite' => false,
                'is_doctor_favorite' => false,
                'is_custom' => false,
                'source' => 'central_catalog',
                'created_at' => $this->resource->created_at?->toIso8601String(),
                'updated_at' => $this->resource->updated_at?->toIso8601String(),
            ];
        }

        // Fallback for generic array or stdClass
        $item = (array) $this->resource;

        return [
            'id' => (string) ($item['id'] ?? ''),
            'trade_name' => (string) ($item['trade_name'] ?? ''),
            'form' => $item['form'] ?? null,
            'strength' => $item['strength'] ?? null,
            'active_ingredient' => $item['active_ingredient'] ?? null,
            'default_dosage' => $item['default_dosage'] ?? null,
            'usage_count' => (int) ($item['usage_count'] ?? 0),
            'is_favorite' => (bool) ($item['is_favorite'] ?? $item['is_doctor_favorite'] ?? false),
            'is_doctor_favorite' => (bool) ($item['is_doctor_favorite'] ?? $item['is_favorite'] ?? false),
            'is_custom' => (bool) ($item['is_custom'] ?? false),
            'source' => $item['source'] ?? 'catalog',
        ];
    }
}
