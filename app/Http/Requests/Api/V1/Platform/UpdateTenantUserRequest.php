<?php

namespace App\Http\Requests\Api\V1\Platform;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantUserRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('userId');
        $tenantId = $this->route('tenantId');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string'],
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['required'],
        ];
    }
}
