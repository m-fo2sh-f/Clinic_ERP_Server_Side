<?php

namespace App\Http\Requests\Api\V1\Platform;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'clinic_name'    => ['required', 'string', 'max:255'],
            'subdomain'      => ['required', 'string', 'alpha_dash', 'max:50', 'unique:domains,domain', 'unique:tenants,id'],
            'admin_name'     => ['required', 'string', 'max:255'],
            'admin_email'    => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:8'],
            'phone'          => ['nullable', 'string', 'max:25'],
        ];
    }

    public function messages(): array
    {
        return [
            'clinic_name.required'    => 'اسم العيادة مطلوب',
            'subdomain.required'      => 'اسم النطاق الفرعي مطلوب',
            'subdomain.alpha_dash'    => 'النطاق الفرعي يجب أن يحتوي فقط على حروف وأرقام وشرطات',
            'subdomain.unique'        => 'هذا النطاق الفرعي محجوز بالفعل',
            'admin_name.required'     => 'اسم مدير العيادة مطلوب',
            'admin_email.required'    => 'البريد الإلكتروني لمدير العيادة مطلوب',
            'admin_email.email'       => 'صيغة البريد الإلكتروني غير صحيحة',
            'admin_password.required' => 'كلمة المرور المؤقتة مطلوبة',
            'admin_password.min'      => 'كلمة المرور يجب ألا تقل عن 8 أحرف',
        ];
    }
}
