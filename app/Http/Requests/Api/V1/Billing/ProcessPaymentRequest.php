<?php

namespace App\Http\Requests\Api\V1\Billing;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payments'                          => ['required', 'array', 'min:1'],
            'payments.*.method'                 => ['required', 'string', Rule::in(PaymentMethod::values())],
            'payments.*.amount'                 => ['required', 'numeric', 'gt:0'],
            'payments.*.transaction_reference'  => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'payments.required'                 => 'يجب إدخال تفاصيل الدفع.',
            'payments.min'                      => 'يجب إدخال دفعة واحدة على الأقل.',
            'payments.*.method.required'        => 'طريقة الدفع مطلوبة.',
            'payments.*.method.in'              => 'طريقة الدفع يجب أن تكون نقداً (cash) أو فيزا (visa).',
            'payments.*.amount.required'        => 'المبلغ مطلوب.',
            'payments.*.amount.gt'              => 'يجب أن يكون المبلغ أكبر من صفر.',
        ];
    }
}
