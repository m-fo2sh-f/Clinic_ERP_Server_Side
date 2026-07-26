<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\LiveQueueService;
use App\Models\LiveQueue;
use App\Helpers\ShiftHelper;


class AppointmentService 
{

    private $liveQueueService;
    public function __construct(LiveQueueService $liveQueueService) {
        $this->liveQueueService = $liveQueueService;
    }
    

    public function getAllAppointmentsForBranch(int|string $branchId, ?string $date = null)
    {
        [$startTime, $endTime] = ShiftHelper::getShiftWindow($date);
    // بنعمل الفلترة بـ whereBetween لضمان السرعة والأمان 🎯
    return Appointment::where('branch_id', $branchId)
        ->with('patient')
        ->whereBetween('appointment_time', [$startTime, $endTime])
        ->orderBy('appointment_time', 'asc')
        ->get();
}

    public function createAppointment(array $data): Appointment
    {
        return DB::transaction(function () use ($data) {
            if (!empty($data['patient_id'])) {
                $patientId = $data['patient_id'];
            } else {
                
                $patient = Patient::firstOrCreate(
                    ['phone' => trim($data['patient']['phone'])],
                    ['name'  => trim($data['patient']['name'])]
                );
                
                $patientId = $patient->id;
            }

            return Appointment::create([
                'branch_id'        => $data['branch_id'],
                'patient_id'       => $patientId,
                'appointment_time' => $data['appointment_time'],
                'type'             => $data['type'],
                'status'           => $data['status'] ?? AppointmentStatus::CONFIRMED->value,
            ]);
        });
    }

    public function updateAppointment(string $id, array $data): Appointment
    {
    return DB::transaction(function () use ($id, $data) {
        // 1. جلب الحجز والمريض المرتبط بيه
        $appointment = Appointment::with('patient')->findOrFail($id);
        
        // 2. تحديث بيانات الحجز بمرونة (لو الحقل مبعوث يرتفع، لو مش مبعوث يفضل بالقديم)
        $appointment->update([
            'appointment_time' => $data['appointment_time'] ?? $appointment->appointment_time,
            'type'             => $data['type']             ?? $appointment->type, 
            'status'           => $data['status']           ?? $appointment->status,
        ]);

        // 3. التعامل الذكي مع بيانات المريض
        if (!empty($data['patient_id'])) {
            
            if ($appointment->patient_id === $data['patient_id']) {
                // المريض هو هو نفس المريض الأصلي للحجز -> نكتفي بتحديث اسمه وهاتفه لو مبعوثين
                if (!empty($data['patient'])) {
                    $appointment->patient->update([
                        'name'  => $data['patient']['name']  ?? $appointment->patient->name,
                        'phone' => $data['patient']['phone'] ?? $appointment->patient->phone,
                    ]);
                }
            } else {
                // تم اختيار مريض مختلف تماماً من القائمة -> نربط الحجز بالـ patient_id الجديد
                $appointment->update(['patient_id' => $data['patient_id']]);
            }

        } elseif (!empty($data['patient']['name']) && !empty($data['patient']['phone'])) {
            
            // مفيش patient_id بس مبعوث اسم ورقم مريض يدوياً
            // بنبحث عنه الأول بـ firstOrCreate عشان نمنع تكراره 🎯
            $patient = Patient::firstOrCreate(
                    ['phone' => trim($data['patient']['phone'])],
                    ['name'  => trim($data['patient']['name'])]
            );

            // نربط الحجز بالمريض (سواء كان قديم أو لسه متكريت جديد)
            $appointment->update(['patient_id' => $patient->id]);
        }

        // لو مبعتناش أي داتا للمريض (زي ريكويست الـ Check-In الصامت)، الحجز هيفضل مربوط بمريضه القديم بسلام 🛡️

        $appointment->load('patient');
        return $appointment;
    });
}

    public function destroyAppointment(string $id): bool
    {
        $appointment = Appointment::findOrFail($id);
        return (bool) $appointment->delete();
    }

    public function checkInAppointment(string $appointmentId): LiveQueue
{
    return DB::transaction(function () use ($appointmentId) {
        $appointment = Appointment::findOrFail($appointmentId);

        // 🛑 1. شرط الأمان: منع تحضير حجز ملغي أو مكتمل سابقاً
        if (in_array($appointment->status, [
            AppointmentStatus::CANCELLED->value,
            AppointmentStatus::COMPLETED->value,
        ])) {
            throw new \InvalidArgumentException('لا يمكن تسجيل حضور حجز ملغي أو مكتمل بالفعل.');
        }

        // 2. تحديث حالة الحجز لـ Checked-In
        $appointment->update(['status' => AppointmentStatus::CHECKED_IN->value]);

        // 3. التأكد من عدم تكرار المريض في صالة انتظار اليوم
        $existingQueue = LiveQueue::where('appointment_id', $appointment->id)->first();
        if ($existingQueue) {
            return $existingQueue; // ارجاع السجل الحالي لتجنب التكرار
        }

        // 4. إنشاء سجل جديد في صالة الانتظار الحية
        return $this->liveQueueService->createNewPatientInQueue([
            'patient_id'     => $appointment->patient_id,
            'appointment_id' => $appointment->id,
        ], $appointment->branch_id);
    });
}
}