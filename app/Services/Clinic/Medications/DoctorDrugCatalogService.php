<?php

declare(strict_types=1);

namespace App\Services\Clinic\Medications;

use App\Models\DoctorDrug;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class DoctorDrugCatalogService
{
    /**
     * Get paginated or listed drugs for a specific doctor.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForDoctor(User $doctor, array $filters = []): LengthAwarePaginator|Collection
    {
        $query = DoctorDrug::forDoctor($doctor->id);

        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(function ($q) use ($term): void {
                $q->where('trade_name', 'LIKE', "%{$term}%")
                    ->orWhere('active_ingredient', 'LIKE', "%{$term}%");
            });
        }

        if (isset($filters['favorites_only']) && filter_var($filters['favorites_only'], FILTER_VALIDATE_BOOLEAN)) {
            $query->where('is_favorite', true);
        }

        $query->orderByDesc('is_favorite')
            ->orderByDesc('usage_count')
            ->orderBy('trade_name');

        if (isset($filters['per_page'])) {
            $perPage = max(5, min(100, (int) $filters['per_page']));

            return $query->paginate($perPage);
        }

        return $query->get();
    }

    /**
     * Create a new drug entry in doctor's custom catalog.
     *
     * @param  array<string, mixed>  $data
     */
    public function createForDoctor(User $doctor, array $data): DoctorDrug
    {
        $tradeName = trim((string) ($data['trade_name'] ?? ''));

        // Check if already exists for this doctor to avoid unique constraint violation
        $existing = DoctorDrug::forDoctor($doctor->id)
            ->where('trade_name', $tradeName)
            ->first();

        if ($existing) {
            $existing->update([
                'form' => $data['form'] ?? $existing->form,
                'strength' => $data['strength'] ?? $existing->strength,
                'active_ingredient' => $data['active_ingredient'] ?? $existing->active_ingredient,
                'default_dosage' => $data['default_dosage'] ?? $existing->default_dosage,
                'is_favorite' => isset($data['is_favorite']) ? (bool) $data['is_favorite'] : $existing->is_favorite,
            ]);

            return $existing;
        }

        return DoctorDrug::create([
            'doctor_id' => $doctor->id,
            'trade_name' => $tradeName,
            'form' => $data['form'] ?? null,
            'strength' => $data['strength'] ?? null,
            'active_ingredient' => $data['active_ingredient'] ?? null,
            'default_dosage' => $data['default_dosage'] ?? null,
            'usage_count' => 0,
            'is_custom' => true,
            'is_favorite' => (bool) ($data['is_favorite'] ?? false),
        ]);
    }

    /**
     * Update an existing doctor drug record.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(DoctorDrug $doctorDrug, array $data): DoctorDrug
    {
        $doctorDrug->update($data);

        return $doctorDrug->fresh();
    }

    /**
     * Delete a doctor drug record.
     */
    public function delete(DoctorDrug $doctorDrug): bool
    {
        return (bool) $doctorDrug->delete();
    }

    /**
     * Toggle favorite status.
     */
    public function toggleFavorite(DoctorDrug $doctorDrug): DoctorDrug
    {
        $doctorDrug->update([
            'is_favorite' => ! $doctorDrug->is_favorite,
        ]);

        return $doctorDrug;
    }
}
