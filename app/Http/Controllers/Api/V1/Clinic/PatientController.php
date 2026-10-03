<?php

namespace App\Http\Controllers\Api\V1\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Patient\PatientHistoryResource;
use App\Http\Resources\Api\V1\Patient\PatientResource;
use App\Models\Patient;
use App\Services\Clinic\PatientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'search' => 'nullable|string|max:100',
        ]);

        $branchId = $request->query('branch_id');
        $search = $request->query('search');

        if ($branchId) {
            $this->authorizeBranchAccess($request->user(), $branchId);
        }

        $patients = $this->patientService->getTenantPatients($branchId, $search);

        return response()->json([
            'status' => 'success',
            'data' => PatientResource::collection($patients),
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

        $isMedicalStaff = $user && method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['doctor', 'clinic_owner']);

        if (! $isMedicalStaff) {
            $patient = $this->patientService->show($id, $branchId);

            return response()->json([
                'status' => 'success',
                'data' => new PatientResource($patient),
            ]);
        }

        $patient = $this->patientService->getPatientHistory($id, $branchId);

        return response()->json([
            'status' => 'success',
            'data' => new PatientHistoryResource($patient),
        ]);
    }

    /**
     * POST /patients — Store a newly created patient record.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $isMedicalStaff = $user && method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['doctor', 'clinic_owner']);

        $rules = [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'gender' => 'nullable|in:male,female',
            'age' => 'nullable|integer|min:0|max:150',
            'date_of_birth' => 'nullable|date',
            'blood_group' => 'nullable|string|max:10',
            'branch_id' => 'nullable|exists:branches,id',
        ];

        if ($isMedicalStaff) {
            $rules['chronic_diseases'] = 'nullable|string|max:1000';
            $rules['allergies'] = 'nullable|string|max:1000';
            $rules['surgeries'] = 'nullable|string|max:1000';
            $rules['medical_history'] = 'nullable|string|max:2000';
        }

        $validated = $request->validate($rules);

        if (! empty($validated['branch_id'])) {
            $this->authorizeBranchAccess($user, $validated['branch_id']);
            unset($validated['branch_id']);
        }

        $patient = $this->patientService->createPatient($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Patient created successfully',
            'data' => new PatientResource($patient),
        ], 201);
    }

    /**
     * PUT/PATCH /patients/{id} — Update patient demographics and medical background.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $patient = Patient::findOrFail($id);
        $user = $request->user();

        // 🔒 1. التحقق من صلاحية الفرع إذا تم تمريره في الطلب
        if ($request->filled('branch_id')) {
            $this->authorizeBranchAccess($user, $request->branch_id);
        }

        $isMedicalStaff = $user && method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['doctor', 'clinic_owner']);

        // 📋 2. القواعد الديموغرافية الأساسية المسموحة للجميع
        $rules = [
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|required|string|max:50',
            'gender' => 'nullable|in:male,female',
            'age' => 'nullable|integer|min:0|max:150',
            'date_of_birth' => 'nullable|date',
            'blood_group' => 'nullable|string|max:10',
            'branch_id' => 'nullable|exists:branches,id',
        ];

        // 🩺 حصر تعديل البيانات الطبية السريرية الحساسة على الطبيب ومالك العيادة فقط
        if ($isMedicalStaff) {
            $rules['chronic_diseases'] = 'nullable|string|max:1000';
            $rules['allergies'] = 'nullable|string|max:1000';
            $rules['surgeries'] = 'nullable|string|max:1000';
            $rules['medical_history'] = 'nullable|string|max:2000';
        }

        $validated = $request->validate($rules);

        // جدول المرضى لا يحتوي على عمود branch_id (المرضى على مستوى العيادة)
        unset($validated['branch_id']);

        // تحديث السجل بالبيانات الصالحة مع دعم تصفير الحقول الاختيارية (null)
        $patient->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Patient profile updated successfully',
            'data' => new PatientResource($patient),
        ], 200);
    }

    /**
     * GET /patients/search — Auto-complete patient search by name, phone, or MRN.
     */
    public function search(Request $request): JsonResponse
    {
        $raw = $request->query('query') ?? $request->query('q', '');
        $query = trim((string) $raw);

        if (mb_strlen($query) < 1) {
            return response()->json(['status' => 'success', 'data' => []]);
        }

        // 🔒 استبعاد البيانات الطبية الحساسة (chronic_diseases, allergies) لحماية الخصوصية
        $patients = Patient::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                    ->orWhere('phone', 'LIKE', "%{$query}%")
                    ->orWhere('medical_number', 'LIKE', "%{$query}%");
            })
            ->select(['id', 'name', 'phone', 'medical_number', 'age', 'gender', 'blood_group'])
            ->limit(15)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $patients,
        ]);
    }

    /**
     * GET /patients/{id}/medical-profile — Medical background and past encounters.
     */
    public function medicalProfile(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if ($user && ! $user->hasAnyRole(['doctor', 'clinic_owner'])) {
            abort(403, 'غير مصرح لموظف الاستقبال بالاطلاع على الملف الطبي السريري للمريض.');
        }

        $patient = Patient::with([
            'encounters' => fn ($q) => $q->orderByDesc('created_at')->limit(10)->with(['doctor', 'prescription.items']),
            'invoices' => fn ($q) => $q->orderByDesc('created_at')->limit(5)->with('payments'),
        ])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $patient->id,
                'name' => $patient->name,
                'phone' => $patient->phone,
                'medical_number' => $patient->medical_number,
                'age' => $patient->age,
                'gender' => $patient->gender,
                'blood_group' => $patient->blood_group,
                'chronic_diseases' => $patient->chronic_diseases,
                'allergies' => $patient->allergies,
                'surgeries' => $patient->surgeries,
                'medical_history' => $patient->medical_history,
                'encounters' => $patient->encounters,
                'invoices' => $patient->invoices,
            ],
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
            'data' => $summary,
        ]);
    }

    /**
     * GET /patients/{id}/history — Patient medical file for Doctor Dashboard.
     */
    public function getHistory(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if ($user && method_exists($user, 'hasAnyRole') && ! $user->hasAnyRole(['doctor', 'clinic_owner'])) {
            abort(403, 'غير مصرح لموظف الاستقبال بالاطلاع على السجل الطبي للمريض.');
        }

        $branchId = $request->query('branch_id');
        $patient = $this->patientService->getPatientHistory($id, $branchId);

        return response()->json([
            'status' => 'success',
            'data' => new PatientHistoryResource($patient),
        ]);
    }

    /**
     * DELETE /patients/{id} — Delete patient record (clinic_owner only).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if ($user && method_exists($user, 'hasRole') && ! $user->hasRole('clinic_owner')) {
            abort(403, 'حذف ملف المريض مقتصر على مالك العيادة فقط.');
        }

        $patient = Patient::findOrFail($id);
        $patient->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'تم حذف ملف المريض بنجاح',
        ], 200);
    }
}
