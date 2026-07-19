<?php

namespace App\Http\Requests\Api\Appointment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
            'appointment_time' => 'required|date|after:now',
            'type'             => 'required|in:Consultation,Follow-up,Emergency',
            'status'           => 'required|in:Confirmed,Cancelled,Completed,Rescheduled',
            'branch_id'        => 'required|exists:branches,id',
            'patient_id'       => 'required|exists:patients,id',
            "patient.name"     => "required_without:patient_id|string|max:255",
            "patient.phone"    => "required_without:patient_id|string|max:255",

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
