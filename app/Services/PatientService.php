<?php

namespace App\Services;

use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Collection;

class PatientService
{
    /**
     * Get all patients for a branch with optional search filter and completed appointments count.
     */
    public function getTenantPatients(?string $branchId = null, ?string $search = null): Collection
    {
        $query = Patient::query();

        // 1. Search by patient name or phone number
        if (!empty($search)) {
            $searchTerm = trim($search);
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('phone', 'LIKE', "%{$searchTerm}%");
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
        ->get(['id', 'name', 'phone', 'age', 'gender', 'medical_history', 'created_at']);
    }

    /**
     * Search patients by query term for auto-complete.
     */
    public function search(Request $request): Collection
    {
        $query = trim($request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return new Collection();
        }

        return Patient::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                  ->orWhere('phone', 'LIKE', "%{$query}%");
            })
            ->select(['id', 'name', 'phone'])
            ->limit(10)
            ->get();
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
}