<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Patient;


class PatientController extends Controller
{
     public function search(Request $request)
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
}
