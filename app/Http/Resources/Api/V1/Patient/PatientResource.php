<?php

namespace App\Http\Resources\Api\V1\Patient;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\V1\Appointment\AppointmentResource;

class PatientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isClinicalStaff = $request->user()?->hasAnyRole(['doctor', 'clinic_owner']);

        $totalCompleted = $this->total_completed_count !== null
            ? (int) $this->total_completed_count
            : (int) ($this->completed_appointments_count ?? $this->appointments()->where('status', 'completed')->count());

        $branchId = $request->query('branch_id');
        $branchCompleted = $this->branch_completed_count !== null
            ? (int) $this->branch_completed_count
            : (int) ($branchId
                ? $this->appointments()->where('status', 'completed')->where('branch_id', $branchId)->count()
                : $totalCompleted);

        return [
            'id'                           => $this->id,
            'medical_number'               => $this->medical_number,
            'name'                         => $this->name,
            'phone'                        => $this->phone,
            'date_of_birth'                => $this->date_of_birth?->toDateString(),
            'age'                          => $this->age,
            'gender'                       => $this->gender,
            'blood_group'                  => $this->blood_group,
            'chronic_diseases'             => $this->when($isClinicalStaff, $this->chronic_diseases),
            'allergies'                    => $this->when($isClinicalStaff, $this->allergies),
            'surgeries'                    => $this->when($isClinicalStaff, $this->surgeries),
            'medical_history'              => $this->when($isClinicalStaff, $this->medical_history),
            'total_completed_count'        => $totalCompleted,
            'branch_completed_count'       => $branchCompleted,
            'completed_appointments_count' => $totalCompleted,
            'appointments'                 => $this->whenLoaded('appointments', function () {
                return $this->appointments->map(function ($appt) {
                    $branchName = $appt->relationLoaded('branch') && $appt->branch
                        ? $appt->branch->name
                        : ($appt->branch_name ?? null);

                    return [
                        'id'               => $appt->id,
                        'appointment_time' => $appt->appointment_time?->toIso8601String() ?? (string) $appt->appointment_time,
                        'status'           => $appt->status instanceof \BackedEnum ? $appt->status->value : $appt->status,
                        'branch_name'      => $branchName,
                        'type'             => $appt->type instanceof \BackedEnum ? $appt->type->value : $appt->type,
                    ];
                });
            }),
            'created_at'                   => $this->created_at?->toIso8601String(),
        ];
    }
}
