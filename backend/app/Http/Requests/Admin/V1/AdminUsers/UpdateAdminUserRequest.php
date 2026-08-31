<?php

namespace App\Http\Requests\Admin\V1\AdminUsers;

use App\Enums\AdminUserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $adminId = $this->route('admin_user')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('admin_users', 'email')->ignore($adminId)],
            'password' => ['nullable', 'string', 'min:8'],
            'status' => ['nullable', Rule::enum(AdminUserStatus::class)],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ];
    }
}
