<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Patient;
use App\Http\Resources\Patients\PatientResource;

class PatientController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $query = trim($request->query('q', ''));

        // لو كاتب أقل من حرفين بنرجع مصفوفة فاضية فوراً لتوفير الاستعلام
        if (mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        $patients = Patient::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                ->orWhere('phone', 'LIKE', "%{$query}%");
            })
            ->select(['id', 'name', 'phone']) // جلب الحقول المطلوبة فقط لسرعة الـ Query
            ->limit(10)
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $patients
        ]);
    }

    /**
     * Get patient full profile with medical history and past appointments.
     * Used by the Doctor Dashboard to display the active patient's medical file.
     */
    public function getHistory(string $id): JsonResponse
    {
        $patient = Patient::with([
            'appointments' => function ($query) {
                $query->orderBy('appointment_time', 'desc')->with('branch');
            },
        ])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'id'              => $patient->id,
                'name'            => $patient->name,
                'phone'           => $patient->phone,
                'age'             => $patient->age,
                'gender'          => $patient->gender,
                'medical_history' => $patient->medical_history,
                'appointments'    => $patient->appointments->map(fn ($appt) => [
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
