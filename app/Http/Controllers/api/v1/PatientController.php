<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Patient;
use App\Services\PatientService;
use App\Http\Resources\Patients\PatientResource;

class PatientController extends Controller
{
    private PatientService $patientService;

    public function __construct(PatientService $patientService)
    {
        $this->patientService = $patientService;
    }

    /**
     * GET /patients — Patient directory listing with completed visit counts.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'search'    => 'nullable|string|max:100',
        ]);

        $branchId = $request->query('branch_id');
        $search   = $request->query('search');

        if ($branchId) {
            $this->authorizeBranchAccess($request->user(), $branchId);
        }

        $patients = $this->patientService->getTenantPatients($branchId, $search);

        return response()->json([
            'status' => 'success',
            'data'   => PatientResource::collection($patients),
        ]);
    }

    /**
     * GET /patients/{id} — Full patient medical profile with appointment history.
     */
    public function show(string $id): JsonResponse
    {
        $patient = $this->patientService->show($id);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'id'                           => $patient->id,
                'name'                         => $patient->name,
                'phone'                        => $patient->phone,
                'age'                          => $patient->age,
                'gender'                       => $patient->gender,
                'medical_history'              => $patient->medical_history,
                'total_completed_count'        => (int) ($patient->total_completed_count ?? $patient->completed_appointments_count ?? 0),
                'branch_completed_count'       => (int) ($patient->branch_completed_count ?? 0),
                'completed_appointments_count' => (int) ($patient->completed_appointments_count ?? 0),
                'appointments'                 => $patient->appointments->map(fn ($appt) => [
                    'id'               => $appt->id,
                    'appointment_time' => $appt->appointment_time,
                    'type'             => $appt->type,
                    'status'           => $appt->status,
                    'branch_name'      => $appt->branch->name ?? null,
                ]),
            ],
        ]);
    }

    /**
     * GET /patients/search — Auto-complete patient search.
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim($request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        $patients = Patient::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                ->orWhere('phone', 'LIKE', "%{$query}%");
            })
            ->select(['id', 'name', 'phone'])
            ->limit(10)
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $patients
        ]);
    }

    /**
     * GET /patients/{id}/history — Patient medical file for Doctor Dashboard.
     */
    public function getHistory(string $id): JsonResponse
    {
        $patient = $this->patientService->show($id);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'id'                           => $patient->id,
                'name'                         => $patient->name,
                'phone'                        => $patient->phone,
                'age'                          => $patient->age,
                'gender'                       => $patient->gender,
                'medical_history'              => $patient->medical_history,
                'total_completed_count'        => (int) ($patient->total_completed_count ?? $patient->completed_appointments_count ?? 0),
                'branch_completed_count'       => (int) ($patient->branch_completed_count ?? 0),
                'completed_appointments_count' => (int) ($patient->completed_appointments_count ?? 0),
                'appointments'                 => $patient->appointments->map(fn ($appt) => [
                    'id'               => $appt->id,
                    'appointment_time' => $appt->appointment_time,
                    'type'             => $appt->type,
                    'status'           => $appt->status,
                    'branch_name'      => $appt->branch->name ?? null,
                ]),
            ],
        ]);
    }
}
