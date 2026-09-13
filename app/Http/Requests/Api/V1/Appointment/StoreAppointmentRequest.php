<?php

namespace App\Http\Requests\Api\V1\Appointment;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize incoming datetime strings before validation runs.
     * Accepts ISO 8601, HTML5 datetime-local (Y-m-d\TH:i), and standard SQL datetime formats.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('appointment_time') && is_string($this->appointment_time)) {
            try {
                $this->merge([
                    'appointment_time' => \Carbon\Carbon::parse($this->appointment_time)->format('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                // Leave as-is so Laravel's validator catches invalid date formats cleanly
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'appointment_time' => ['required', 'date', 'date_format:Y-m-d H:i:s'],
            'branch_id'        => 'required|exists:branches,id',
            'doctor_id'        => 'nullable|exists:users,id',
            'type'             => ['required', Rule::enum(AppointmentType::class)],
            'status'           => ['required', Rule::enum(AppointmentStatus::class)],
            'patient_id'       => 'nullable|exists:patients,id',
            'patient'          => 'required_without:patient_id|array',
            'patient.name'             => 'required_without:patient_id|string|max:255',
            'patient.phone'            => 'required_without:patient_id|string|max:255',
            'patient.age'              => 'nullable|integer|min:0|max:150',
            'patient.gender'           => 'nullable|in:male,female',
            'patient.medical_number'   => 'nullable|string|max:100',
            'patient.blood_group'      => 'nullable|string|max:10',
            'patient.chronic_diseases' => 'nullable|string|max:1000',
            'patient.allergies'        => 'nullable|string|max:1000',
            'patient.surgeries'        => 'nullable|string|max:1000',
            'patient.medical_history'  => 'nullable|string|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Name is required',
            'phone.required' => 'Phone is required',
            'appointment_time.required' => 'Appointment time is required',
            'branch_id.required' => 'Branch is required',
            'patient_id.required' => 'Patient is required',
            'type.required' => 'Type is required',
            'status.required' => 'Status is required',
            'patient.name.required' => 'Patient name is required',
            'patient.phone.required' => 'Patient phone is required',
        ];
    }
}
