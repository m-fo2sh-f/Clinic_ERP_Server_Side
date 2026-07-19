<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AppointmentService 
{

    public function getAllAppointmentsForBranch(int|string $branchId, ?string $date = null)
{
    if ($date) {
        $selectedDate = Carbon::parse($date);
        $startTime    = $selectedDate->copy()->startOfDay()->addHours(5); 
        $endTime      = $selectedDate->copy()->addDay()->startOfDay()->addHours(5); 
    } else {
        $now = now(); 

        if ($now->hour < 5) {
            $startTime = now()->subDay()->startOfDay()->addHours(5); 
            $endTime   = now()->startOfDay()->addHours(5);         
        } else {
            $startTime = now()->startOfDay()->addHours(5);          
            $endTime   = now()->addDay()->startOfDay()->addHours(5);   // بكره 5 الفجر
        }
    }

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
                $newPatient = Patient::create([
                    'name'  => $data['patient']['name'],
                    'phone' => $data['patient']['phone'],
                ]);
                $patientId = $newPatient->id;
            }

            return Appointment::create([
                'branch_id'        => $data['branch_id'],
                'patient_id'       => $patientId,
                'appointment_time' => $data['appointment_time'],
                'type'             => $data['type'],
                'status'           => $data['status'] ?? 'Confirmed',
            ]);
        });
    }

    public function updateAppointment(string $id, array $data): Appointment
    {
        return DB::transaction(function () use ($id, $data) {
            // استخدام findOrFail لضمان الحماية ورمي 404 لو مش موجود
            $appointment = Appointment::with('patient')->findOrFail($id);
            
            $appointment->update([
                'appointment_time' => $data['appointment_time'],
                'type'             => $data['type'], 
                'status'           => $data['status'] ?? $appointment->status,
            ]);

            if (!empty($data['patient_id'])) {
                if ($appointment->patient_id === $data['patient_id']) {
                    $appointment->patient->update([
                        'name'  => $data['patient']['name'] ?? $appointment->patient->name,
                        'phone' => $data['patient']['phone'] ?? $appointment->patient->phone,
                    ]);
                } else {
                    $appointment->update([
                        'patient_id' => $data['patient_id'],
                    ]);
                }
            } else {
                $newPatient = Patient::create([
                    'name'  => $data['patient']['name'],
                    'phone' => $data['patient']['phone'],
                ]);

                $appointment->update([
                    'patient_id' => $newPatient->id,
                ]);
            }

            $appointment->load('patient');
            return $appointment;
        });
    }

    public function destroyAppointment(string $id): bool
    {
        $appointment = Appointment::findOrFail($id);
        return (bool) $appointment->delete();
    }
}