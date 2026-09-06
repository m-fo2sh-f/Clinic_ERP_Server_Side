<?php

namespace App\Http\Requests\Api\Platform;

use Illuminate\Foundation\Http\FormRequest;

class ToggleTenantStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() && (bool) $this->user()->is_super_admin;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_active' => 'required|boolean',
        ];
    }
}
