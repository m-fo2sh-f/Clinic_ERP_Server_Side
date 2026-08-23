<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Patient;
use App\Services\PatientService;
use App\Services\ConsultationService;
use App\Http\Resources\Patients\PatientResource;
use App\Http\Resources\Patients\PatientHistoryResource;

class PatientController extends Controller
{
    private PatientService $patientService;
    private ConsultationService $consultationService;

    public function __construct(PatientService $patientService, ConsultationService $consultationService)
    {
        $this->patientService = $patientService;
        $this->consultationService = $consultationService;
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
        $patient = $this->consultationService->getPatientHistory($id);

        return response()->json([
            'status' => 'success',
            'data'   => new PatientHistoryResource($patient),
        ]);
    }

    /**
     * PUT/PATCH /patients/{id} — Update patient demographics and medical background.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $patient = Patient::findOrFail($id);

    // 🔒 1. التحقق من صلاحية الفرع إذا تم تمريره في الطلب
        if ($request->filled('branch_id')) {
            $this->authorizeBranchAccess($request->user(), $request->branch_id);
        }

        // 🩺 2. القواعد الأساسية المسموحة لجميع الأدوار الطبية (البيانات السريرية والديموغرافية)
        $rules = [
            'gender'           => 'nullable|in:male,female',
            'age'              => 'nullable|integer|min:0|max:150',
            'date_of_birth'    => 'nullable|date',
            'blood_group'      => 'nullable|string|max:10',
            'chronic_diseases' => 'nullable|string|max:1000',
            'allergies'        => 'nullable|string|max:1000',
            'surgeries'        => 'nullable|string|max:1000',
            'medical_history'  => 'nullable|string|max:2000',
            'branch_id'        => 'nullable|exists:branches,id',
        ];

        // 📋 3. قصر تعديل الاسم والهاتف على الريسبشن وأدمن العيادة فقط
        if ($request->user()->hasAnyRole(['receptionist', 'clinic_owner', 'tenant_admin'])) {
            $rules['name']  = 'sometimes|required|string|max:255';
            $rules['phone'] = 'sometimes|required|string|max:50';
        }

        $validated = $request->validate($rules);

        // إزالة القيم الفارغة وتحديث السجل
        $patient->update(array_filter($validated, fn ($val) => !is_null($val)));

        return response()->json([
            'status'  => 'success',
            'message' => 'Patient profile updated successfully',
            'data'    => new PatientResource($patient),
        ], 200);
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
        $patient = $this->consultationService->getPatientHistory($id);

        return response()->json([
            'status' => 'success',
            'data'   => new PatientHistoryResource($patient),
        ]);
    }
}
