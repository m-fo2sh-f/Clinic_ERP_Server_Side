<?php

namespace App\Http\Requests\Api\V1\Queue;

use Illuminate\Foundation\Http\FormRequest;

class QueueBranchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }

        $branchId = $this->input('branch_id') ?? $this->query('branch_id');

        // Clinic owner has full access across all branches
        if ($user->hasRole('clinic_owner')) {
            return true;
        }

        // Branch-scoped staff must belong to the requested branch
        if ($branchId && method_exists($user, 'branches')) {
            return $user->branches()->where('branches.id', $branchId)->exists();
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => 'required|uuid|exists:branches,id',
        ];
    }
}
