<?php

namespace App\Http\Controllers\Api\V1\Clinic;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Patient;
use App\Services\Clinic\PatientService;
use App\Services\Clinic\ConsultationService;
use App\Http\Resources\Api\V1\Patient\PatientResource;
use App\Http\Resources\Api\V1\Patient\PatientHistoryResource;

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
    public function show(Request $request, string $id): JsonResponse
    {
        $branchId = $request->query('branch_id');
        $user = $request->user();

        if ($branchId) {
            $this->authorizeBranchAccess($user, $branchId);
        }

        if ($user && $user->hasRole('receptionist') && !$user->hasAnyRole(['doctor', 'clinic_owner'])) {
            $patient = $this->patientService->show($id, $branchId);
            return response()->json([
                'status' => 'success',
                'data'   => new PatientResource($patient),
            ]);
        }

        $patient = $this->consultationService->getPatientHistory($id, $branchId);

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

        // 📋 3. قصر تعديل الاسم والهاتف على الريسبشن ومالك العيادة فقط
        if ($request->user()->hasAnyRole(['receptionist', 'clinic_owner'])) {
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
     * GET /patients/search — Auto-complete patient search by name, phone, or MRN.
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim($request->query('q', ''));

        if (mb_strlen($query) < 1) {
            return response()->json(['status' => 'success', 'data' => []]);
        }

        $patients = Patient::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                  ->orWhere('phone', 'LIKE', "%{$query}%")
                  ->orWhere('medical_number', 'LIKE', "%{$query}%");
            })
            ->select(['id', 'name', 'phone', 'medical_number', 'age', 'gender'])
            ->limit(15)
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $patients
        ]);
    }

    /**
     * GET /patients/{id}/summary — Quick patient preview context for modal UI.
     */
    public function summary(string $id): JsonResponse
    {
        $summary = $this->patientService->getPatientSummary($id);

        return response()->json([
            'status' => 'success',
            'data'   => $summary,
        ]);
    }

    /**
     * GET /patients/{id}/history — Patient medical file for Doctor Dashboard.
     */
    public function getHistory(Request $request, string $id): JsonResponse
    {
        $branchId = $request->query('branch_id');
        $patient = $this->consultationService->getPatientHistory($id, $branchId);

        return response()->json([
            'status' => 'success',
            'data'   => new PatientHistoryResource($patient),
        ]);
    }
}
