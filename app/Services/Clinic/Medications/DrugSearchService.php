<?php

declare(strict_types=1);

namespace App\Services\Clinic\Medications;

use App\Models\CentralDrug;
use App\Models\DoctorDrug;
use App\Models\User;
use Illuminate\Support\Collection;

class DrugSearchService
{
    /**
     * Search drugs prioritizing the doctor's personal/frequent drugs first,
     * followed by the central Egyptian pharmaceutical database.
     *
     * @return Collection<int, DoctorDrug|CentralDrug>
     */
    public function search(?string $term, User $doctor, int $limit = 15): Collection
    {
        $term = trim((string) $term);
        $results = collect();

        // 1. Fetch doctor's personal and frequent drugs
        $doctorQuery = DoctorDrug::forDoctor($doctor->id);

        if ($term !== '') {
            $doctorMatches = $doctorQuery
                ->where(function ($q) use ($term): void {
                    $q->where('trade_name', 'LIKE', "{$term}%")
                        ->orWhere('trade_name', 'LIKE', "%{$term}%")
                        ->orWhere('active_ingredient', 'LIKE', "%{$term}%");
                })
                ->orderByRaw('CASE WHEN trade_name LIKE ? THEN 1 WHEN trade_name LIKE ? THEN 2 ELSE 3 END', ["{$term}%", "%{$term}%"])
                ->orderByDesc('usage_count')
                ->limit(min(10, $limit))
                ->get();
        } else {
            // Preload doctor's top frequent drugs when search term is empty (Zero-latency focus)
            $doctorMatches = $doctorQuery
                ->orderByDesc('is_favorite')
                ->orderByDesc('usage_count')
                ->limit(min(12, $limit))
                ->get();
        }

        $results = $results->merge($doctorMatches);

        // Track normalized names to avoid duplication with central catalog
        $seenNames = $doctorMatches
            ->pluck('trade_name')
            ->map(fn (string $name): string => strtolower(trim($name)))
            ->flip()
            ->all();

        // 2. Fetch from Central Egyptian Drugs Catalog if we need more results and term is provided
        if ($term !== '' && $results->count() < $limit) {
            $remainingLimit = $limit - $results->count();

            $centralMatches = CentralDrug::search($term, $remainingLimit * 2)
                ->get()
                ->reject(function (CentralDrug $central) use ($seenNames): bool {
                    $norm = strtolower(trim($central->trade_name));

                    return isset($seenNames[$norm]);
                })
                ->take($remainingLimit);

            $results = $results->merge($centralMatches);
        }

        return $results;
    }
}
