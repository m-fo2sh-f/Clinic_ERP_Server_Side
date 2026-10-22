<?php

declare(strict_types=1);

namespace App\Services\Clinic\Medications;

use App\Models\CentralDrug;
use App\Models\DoctorDrug;
use Illuminate\Support\Facades\Log;

class PrescriptionAutoLearnService
{
    /**
     * Learn from prescribed medications by automatically registering or incrementing
     * the usage count in the doctor's personal prescribing catalog.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function recordPrescriptionUsage(string $doctorId, array $items): void
    {
        if (empty($doctorId) || empty($items)) {
            return;
        }

        try {
            foreach ($items as $item) {
                $tradeName = trim((string) ($item['drug_name'] ?? ''));
                if ($tradeName === '') {
                    continue;
                }

                $dosage = trim((string) ($item['dosage'] ?? ''));

                $isCentral = CentralDrug::where('trade_name', $tradeName)->exists();

                $doctorDrug = DoctorDrug::firstOrCreate(
                    [
                        'doctor_id' => $doctorId,
                        'trade_name' => $tradeName,
                    ],
                    [
                        'form' => $item['form'] ?? null,
                        'strength' => $item['strength'] ?? null,
                        'active_ingredient' => $item['active_ingredient'] ?? null,
                        'default_dosage' => $dosage !== '' ? $dosage : null,
                        'usage_count' => 0,
                        'is_custom' => ! $isCentral,
                        'is_favorite' => false,
                    ]
                );

                $doctorDrug->increment('usage_count');

                // Update default dosage when specified
                if ($dosage !== '') {
                    $doctorDrug->update(['default_dosage' => $dosage]);
                }
            }
        } catch (\Throwable $e) {
            // Never break the consultation flow if auto-learning encounters an anomaly
            Log::warning('Prescription auto-learn failed gracefully: '.$e->getMessage(), [
                'doctor_id' => $doctorId,
                'items_count' => count($items),
            ]);
        }
    }
}
