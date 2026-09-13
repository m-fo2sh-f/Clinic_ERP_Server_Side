<?php

namespace App\Http\Requests\Api\V1\Billing;

use Illuminate\Foundation\Http\FormRequest;

class AddServiceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => ['required', 'string', 'exists:services,id'],
            'quantity'   => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'service_id.required' => 'يرجى اختيار الخدمة.',
            'service_id.exists'   => 'الخدمة المختارة غير موجودة.',
            'quantity.min'        => 'الكمية يجب أن تكون 1 على الأقل.',
        ];
    }
}
