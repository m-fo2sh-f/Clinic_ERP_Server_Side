<?php

namespace App\Http\Requests\Api\Appointment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'appointment_time' => 'required|date_format:Y-m-d H:i:s',
            'branch_id'        => 'required|exists:branches,id',
            'type'             => 'required|in:check_up,follow_up,consultation',
            'status'           => 'required|in:checked_in,no_show,canceled,pending',
            'patient_id'       => 'nullable|exists:patients,id',
            'patient'          => 'required_without:patient_id|array',
            'patient.name'     => 'required_without:patient_id|string|max:255',
            'patient.phone'    => 'required_without:patient_id|string|max:255',
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
