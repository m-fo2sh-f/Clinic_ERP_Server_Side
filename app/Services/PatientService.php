<?php

namespace App\Services;

use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Collection;

use Illuminate\Support\Facades\DB;

class PatientService
{
    /**
     * Get all patients for a branch with optional search filter and completed appointments count.
     */
    public function getTenantPatients(?string $branchId = null, ?string $search = null): Collection
    {
        $query = Patient::query();

        // 1. Search by patient name, phone, or MRN using native MySQL indexing
        if (!empty($search)) {
            $searchTerm = trim($search);
            $query->where(function ($q) use ($searchTerm) {
                $this->applyOptimizedSearch($q, $searchTerm);
            });
        }

        // 2. Fetch all patients with completed visit counts
        return $query->withCount([
            // Total completed appointments across all clinic branches
            'appointments as total_completed_count' => function ($q) {
                $q->where('status', 'completed');
            },
            // Completed appointments in the active branch only
            'appointments as branch_completed_count' => function ($q) use ($branchId) {
                $q->where('status', 'completed');
                if (!empty($branchId)) {
                    $q->where('branch_id', $branchId);
                }
            },
            // Direct alias for backward/forward compatibility
            'appointments as completed_appointments_count' => function ($q) {
                $q->where('status', 'completed');
            }
        ])
        ->orderByDesc('created_at')
        ->get(['id', 'medical_number', 'name', 'phone', 'age', 'gender', 'medical_history', 'created_at']);
    }

    /**
     * Search patients by query term for auto-complete.
     */
    public function search(Request $request): Collection
    {
        $query = trim($request->query('q', ''));

        if (mb_strlen($query) < 1) {
            return new Collection();
        }

        return Patient::query()
            ->where(function ($q) use ($query) {
                $this->applyOptimizedSearch($q, $query);
            })
            ->select(['id', 'name', 'phone', 'medical_number', 'age', 'gender'])
            ->limit(15)
            ->get();
    }

    /**
     * Apply native MySQL high-performance search branching.
     * Eliminates full table scans at 10M+ scale using composite B-Tree indexes and Full-Text index.
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param string $term
     */
    public function applyOptimizedSearch($query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        // 1. Numeric / Phone query: Prefix match leveraging composite B-Tree indexes
        $isNumericOrPhone = preg_match('/^[0-9+\s\-]+$/', $term) || preg_match('/^(mrn|pt)[-_\d]/i', $term);

        if ($isNumericOrPhone) {
            $cleanPhone = preg_replace('/[^\d]/', '', $term);
            $query->where(function ($q) use ($cleanPhone, $term) {
                if (!empty($cleanPhone)) {
                    $q->where('phone', 'LIKE', "{$cleanPhone}%");
                }
                $q->orWhere('medical_number', 'LIKE', "{$term}%");
            });
            return;
        }

        // 2. Text / Name query (>= 3 characters): Native MySQL Full-Text search
        if (mb_strlen($term) >= 3) {
            $booleanQuery = $this->formatBooleanQuery($term);
            if (!empty($booleanQuery)) {
                $query->whereRaw("MATCH(name, phone) AGAINST(? IN BOOLEAN MODE)", [$booleanQuery]);
                return;
            }
        }

        // 3. Short strings (< 3 characters) or fallback: Prefix match
        $query->where('name', 'LIKE', "{$term}%");
    }

    /**
     * Format input for MySQL Full-Text Boolean Mode (+word*), stripping problematic operators.
     */
    protected function formatBooleanQuery(string $term): string
    {
        // Strip problematic Boolean mode operators: + - * @ ~ < > ( ) "
        $sanitized = preg_replace('/[+\-><()~*\"@%]+/', ' ', $term);
        $words = array_filter(explode(' ', trim((string) $sanitized)));

        if (empty($words)) {
            return '';
        }

        return implode(' ', array_map(fn ($word) => "+{$word}*", $words));
    }

    /**
     * Get complete details and appointment history for a patient.
     */
    public function show(string $id): Patient
    {
        return Patient::withCount(['appointments as completed_appointments_count' => function ($q) {
            $q->where('status', 'completed');
        }])
        ->with([
            'appointments' => function ($query) {
                $query->orderBy('appointment_time', 'desc')->with('branch');
            },
        ])
        ->findOrFail($id);
    }

    /**
     * Concurrency-safe automatic MRN sequence generator scoped per tenant.
     * @return array{mrn_sequence: int, medical_number: string}
     */
    public function generateNextMedicalNumber(?string $tenantId = null): array
    {
        $tenantId = $tenantId ?? (function_exists('tenant') ? tenant('id') : null);

        return DB::transaction(function () use ($tenantId) {
        $query = Patient::query();

        if (!empty($tenantId)) {
            $query->where('tenant_id', $tenantId);
        }

        // 🎯 استعلام فوري يعتمد على الفهرس idx_patients_tenant_mrn_seq بدون فحص نصوص
        $maxSequence = $query->lockForUpdate()->max('mrn_sequence') ?? 10000;
        $nextSequence = $maxSequence + 1;
        $mrnCode = 'MRN-' . str_pad($nextSequence, 5, '0', STR_PAD_LEFT);

        return [
            'mrn_sequence'   => $nextSequence,
            'medical_number' => $mrnCode,
        ];
    });
    }

    /**
     * Create new patient with auto-generated MRN under atomic lock.
     */
    public function createPatient(array $data): Patient
    {
        return DB::transaction(function () use ($data) {
            $mrnData = $this->generateNextMedicalNumber();

            return Patient::create([
                'mrn_sequence'     => $mrnData['mrn_sequence'],
                'medical_number'   => !empty($data['medical_number']) ? trim($data['medical_number']) : $mrnData['medical_number'],
                'name'             => trim($data['name'] ?? 'مريض جديد'),
                'phone'            => preg_replace('/[^\d]/', '', (string)($data['phone'] ?? '')),
                'age'              => isset($data['age']) && $data['age'] !== '' && !is_null($data['age']) ? (int)$data['age'] : null,
                'gender'           => !empty($data['gender']) ? $data['gender'] : null,
                'blood_group'      => $data['blood_group'] ?? null,
                'chronic_diseases' => $data['chronic_diseases'] ?? null,
                'allergies'        => $data['allergies'] ?? null,
                'surgeries'        => $data['surgeries'] ?? null,
                'medical_history'  => $data['medical_history'] ?? null,
            ]);
        });
    }

    /**
     * Normalize English/Arabic patient names for typo-resilient matching.
     * Trims spaces, lowercases English letters, strips diacritics, and normalizes Arabic character variations.
     */
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = preg_replace('/\s+/', ' ', $name);

        // Remove Arabic diacritics (tashkeel) and tatweel
        $name = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $name);

        // Normalize Arabic letter variations: [أ/إ/آ -> ا, ة -> ه, ى -> ي]
        $search  = ['أ', 'إ', 'آ', 'ة', 'ى'];
        $replace = ['ا', 'ا', 'ا', 'ه', 'ي'];

        return str_replace($search, $replace, $name);
    }

    /**
     * Safely resolve patient ID using lockForUpdate to prevent concurrency race conditions and duplicates.
     * Sanitizes phone and name, matching existing patients on normalized (phone + name).
     */
    public function resolvePatient(array $data): string
    {
        if (!empty($data['patient_id'])) {
            if (!empty($data['patient'])) {
                $this->updatePatientDetails($data['patient_id'], $data['patient']);
            }
            return $data['patient_id'];
        }

        // Extract raw name & phone from nested or flat payload structures
        $rawName  = $data['patient']['name']  ?? $data['patientName']  ?? $data['name']  ?? '';
        $rawPhone = $data['patient']['phone'] ?? $data['patientPhone'] ?? $data['phone'] ?? '';

        // Sanitize name: trim extra whitespace and reduce inner multiple spaces to single space
        $name  = trim(preg_replace('/\s+/', ' ', (string) $rawName));
        // Sanitize phone: strip non-digit characters (e.g. "010-1234-5678" -> "01012345678")
        $phone = preg_replace('/[^\d]/', '', (string) $rawPhone);

        return DB::transaction(function () use ($phone, $name, $data) {
            $patientPayload = $data['patient'] ?? $data;
            $patientPayload['phone'] = $phone;
            $patientPayload['name']  = $name;
            if (isset($data['patientAge']) || isset($data['age'])) {
                $patientPayload['age'] = $data['patientAge'] ?? $data['age'] ?? null;
            }
            if (isset($data['patientGender']) || isset($data['gender'])) {
                $patientPayload['gender'] = $data['patientGender'] ?? $data['gender'] ?? null;
            }

            if (!empty($phone) && !empty($name)) {
                $candidates = Patient::where('phone', $phone)->lockForUpdate()->get();
                $normalizedInputName = self::normalizeName($name);

                foreach ($candidates as $candidate) {
                    if (self::normalizeName($candidate->name) === $normalizedInputName) {
                        $this->updatePatientDetails($candidate->id, $patientPayload);
                        return $candidate->id;
                    }
                }
            }

            $newPatient = $this->createPatient($patientPayload);

            return $newPatient->id;
        });
    }

    /**
     * Get patient summary with MRN and total completed/scheduled appointment counts for UI preview context.
     */
    public function getPatientSummary(string $patientId): array
    {
        $patient = Patient::withCount([
            'appointments as total_completed_count' => function ($q) {
                $q->where('status', 'completed');
            },
            'appointments as total_appointments_count'
        ])->findOrFail($patientId);

        return [
            'id'                       => $patient->id,
            'medical_number'           => $patient->medical_number,
            'name'                     => $patient->name,
            'phone'                    => $patient->phone,
            'age'                      => $patient->age,
            'gender'                   => $patient->gender,
            'total_completed_count'    => (int) $patient->total_completed_count,
            'total_appointments_count' => (int) $patient->total_appointments_count,
        ];
    }

    /**
     * Explicitly update patient demographic information with row locking.
     */
    public function updatePatientDetails(string $patientId, array $data): Patient
    {
        return DB::transaction(function () use ($patientId, $data) {
            $patient = Patient::lockForUpdate()->findOrFail($patientId);

            $updates = array_filter([
                'name'             => isset($data['name']) && trim($data['name']) !== '' ? trim($data['name']) : null,
                'phone'            => isset($data['phone']) && trim($data['phone']) !== '' ? trim($data['phone']) : null,
                'age'              => isset($data['age']) && $data['age'] !== '' && !is_null($data['age']) ? (int)$data['age'] : null,
                'gender'           => !empty($data['gender']) ? $data['gender'] : null,
                'medical_number'   => !empty($data['medical_number']) ? trim($data['medical_number']) : null,
                'blood_group'      => $data['blood_group'] ?? null,
                'chronic_diseases' => $data['chronic_diseases'] ?? null,
                'allergies'        => $data['allergies'] ?? null,
                'surgeries'        => $data['surgeries'] ?? null,
                'medical_history'  => $data['medical_history'] ?? null,
            ], fn ($val) => !is_null($val));

            if (!empty($updates)) {
                $patient->update($updates);
            }

            return $patient;
        });
    }
}