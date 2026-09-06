<?php

namespace App\Http\Requests\Api\Appointment;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends FormRequest
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
            'appointment_time'       => 'sometimes|required|date_format:Y-m-d H:i:s',
            'branch_id'              => 'sometimes|required|exists:branches,id',
            'doctor_id'              => 'nullable|exists:users,id',
            'type'                   => ['sometimes', 'required', Rule::enum(AppointmentType::class)],
            'status'                 => ['sometimes', 'required', Rule::enum(AppointmentStatus::class)],
            'strategy'               => 'nullable|string|in:UPDATE_CURRENT,REASSIGN_EXISTING,CREATE_AND_ASSIGN',
            'patient_id'             => 'nullable|exists:patients,id',
            'patient'                => 'nullable|array',
            'patient.name'           => 'nullable|string|max:255',
            'patient.phone'          => 'nullable|string|max:255',
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
            'appointment_time.required' => 'يرجى إدخال موعد الحجز.',
            'appointment_time.after'    => 'يجب أن يكون موعد الحجز في المستقبل.',
            
            'type.required' => 'يرجى اختيار نوع الموعد.',
            'type.in'       => 'النوع المدخل غير صحيح.',
            
            'status.required' => 'يرجى اختيار حالة الموعد.',
            'status.in'       => 'الحالة المدخلة غير صحيحة.',
            
            'branch_id.required' => 'يرجى اختيار الفرع.',
            'branch_id.exists'   => 'الفرع غير صحيح.',
            
            'patient_id.required' => 'يرجى إدخال المريض أو إنشاء مريض جديد.',
            'patient_id.exists'   => 'المريض غير صحيح.',
            
            'patient.name.required_without' => 'يرجى إدخال اسم المريض.',
            'patient.name.max'              => 'اسم المريض يجب ألا يتجاوز 255 حرفًا.',
            
            'patient.phone.required_without' => 'يرجى إدخال رقم هاتف المريض.',
            'patient.phone.max'              => 'رقم هاتف المريض يجب ألا يتجاوز 255 حرفًا.',
        ];
    }
}
