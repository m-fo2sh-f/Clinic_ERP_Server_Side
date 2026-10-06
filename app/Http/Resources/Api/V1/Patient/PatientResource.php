<?php

namespace App\Http\Resources\Api\V1\Patient;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isClinicalStaff = $user && method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(['doctor', 'clinic_owner']);

        // 🚀 صفر كويريز: قراءة العد من السمات المحسوبة مسبقاً (withCount) دون استعلام الـ DB
        $totalCompleted = (int) (
            $this->total_completed_count
            ?? $this->completed_appointments_count
            ?? 0
        );

        $branchCompleted = (int) (
            $this->branch_completed_count
            ?? $totalCompleted
        );

        return [
            'id' => $this->id,
            'medical_number' => $this->medical_number,
            'name' => $this->name,
            'phone' => $this->phone,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'age' => $this->age,
            'gender' => $this->gender,
            'blood_group' => $this->blood_group,

            // 🩺 حقول التاريخ الطبي والتشخيص تظهر للأطباء والمالك فقط
            'chronic_diseases' => $this->when($isClinicalStaff, $this->chronic_diseases),
            'allergies' => $this->when($isClinicalStaff, $this->allergies),
            'surgeries' => $this->when($isClinicalStaff, $this->surgeries),
            'medical_history' => $this->when($isClinicalStaff, $this->medical_history),

            'total_completed_count' => $totalCompleted,
            'branch_completed_count' => $branchCompleted,
            'completed_appointments_count' => $totalCompleted,
            'completed_encounters_count' => (int) ($this->completed_encounters_count ?? 0),

            // 🩺 الكشوفات والزيارات الطبية الفعلية (Encounters)
            'encounters' => $this->whenLoaded('encounters', function () use ($isClinicalStaff) {
                return $this->encounters->map(function ($encounter) use ($isClinicalStaff) {
                    $branchName = $encounter->relationLoaded('branch') && $encounter->branch
                        ? $encounter->branch->name
                        : ($encounter->branch_name ?? null);

                    $doctorName = $encounter->relationLoaded('doctor') && $encounter->doctor
                        ? $encounter->doctor->name
                        : null;

                    $invoiceData = null;
                    if ($encounter->relationLoaded('invoice') && $encounter->invoice) {
                        $invoiceData = [
                            'id' => (string) $encounter->invoice->id,
                            'invoice_number' => $encounter->invoice->invoice_number,
                            'total' => (float) $encounter->invoice->total,
                            'payment_status' => $encounter->invoice->payment_status instanceof \BackedEnum
                                ? $encounter->invoice->payment_status->value
                                : (string) $encounter->invoice->payment_status,
                        ];
                    }

                    return [
                        'id' => (string) $encounter->id,
                        'started_at' => $encounter->started_at?->toIso8601String() ?? $encounter->created_at?->toIso8601String(),
                        'completed_at' => $encounter->completed_at?->toIso8601String(),
                        'status' => $encounter->status instanceof \BackedEnum ? $encounter->status->value : (string) $encounter->status,
                        'type' => $encounter->type instanceof \BackedEnum ? $encounter->type->value : (string) $encounter->type,
                        'doctor_name' => $doctorName,
                        'branch_name' => $branchName,
                        'invoice' => $invoiceData,

                        // 🔒 التفاصيل السريرية الحساسة تظهر فقط للأطباء والمالك (حماية الخصوصية الطبية)
                        'chief_complaint' => $this->when($isClinicalStaff, $encounter->chief_complaint),
                        'clinical_examination' => $this->when($isClinicalStaff, $encounter->clinical_examination),
                        'diagnosis' => $this->when($isClinicalStaff, $encounter->diagnosis),
                        'vitals' => $this->when($isClinicalStaff, $encounter->vitals),
                    ];
                });
            }),

            // يتم تضمين المواعيد فقط في حال تم تحميلها مسبقاً (Eager Loaded)
            'appointments' => $this->whenLoaded('appointments', function () {
                return $this->appointments->map(function ($appt) {
                    $branchName = $appt->relationLoaded('branch') && $appt->branch
                        ? $appt->branch->name
                        : ($appt->branch_name ?? null);

                    $doctorName = $appt->relationLoaded('doctor') && $appt->doctor
                        ? $appt->doctor->name
                        : null;

                    return [
                        'id' => $appt->id,
                        'appointment_time' => $appt->appointment_time?->toIso8601String() ?? (string) $appt->appointment_time,
                        'status' => $appt->status instanceof \BackedEnum ? $appt->status->value : (string) $appt->status,
                        'branch_name' => $branchName,
                        'doctor_name' => $doctorName,
                        'type' => $appt->type instanceof \BackedEnum ? $appt->type->value : (string) $appt->type,
                    ];
                });
            }),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
