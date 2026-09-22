<?php

namespace App\Http\Resources\Api\V1\Appointment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\V1\Patient\PatientResource;
use App\Http\Resources\Api\V1\Prescription\PrescriptionResource;

class AppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        // 🩺 التحقق من الصلاحيات: الحقول الطبية تظهر فقط للدكتور ومالك العيادة
        $canViewClinicalDetails = $user && method_exists($user, 'hasAnyRole') 
            && $user->hasAnyRole(['doctor', 'clinic_owner']);

        // معالجة مصفوفة التشخيص
        $diagnosisArray = is_array($this->diagnosis)
            ? $this->diagnosis
            : (is_string($this->diagnosis) ? json_decode($this->diagnosis, true) : []);
            
        if (is_string($diagnosisArray)) {
            $diagnosisArray = json_decode($diagnosisArray, true) ?: [];
        }

        return [
            'id'                    => $this->id,
            'branch_id'             => $this->branch_id,
            'doctor_id'             => $this->doctor_id,
            'patient'               => new PatientResource($this->whenLoaded('patient', $this->patient)),
            'appointment_time'      => $this->appointment_time?->toIso8601String() ?? (string) $this->appointment_time,
            'type'                  => $this->type instanceof \BackedEnum ? $this->type->value : $this->type,
            'status'                => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'chief_complaint'       => $this->chief_complaint,

            // 🔒 حقول طبية محجوبة عن موظف الاستقبال ومتاحة للطبيب والمالك فقط:
            'diagnosis'             => $this->when($canViewClinicalDetails, $diagnosisArray),
            'clinical_examination'  => $this->when($canViewClinicalDetails, $this->clinical_examination),
            'vitals'                => $this->when($canViewClinicalDetails, $this->vitals),
            'prescription'          => $this->when($canViewClinicalDetails, new PrescriptionResource($this->whenLoaded('prescription'))),

            'started_at'            => $this->started_at?->toIso8601String(),
            'completed_at'          => $this->completed_at?->toIso8601String(),
            'branch_name'           => $this->whenLoaded('branch', fn () => $this->branch->name),
            'doctor'                => $this->whenLoaded('doctor', fn () => [
                'id'   => $this->doctor->id,
                'name' => $this->doctor->name,
            ]),
        ];
    }
}