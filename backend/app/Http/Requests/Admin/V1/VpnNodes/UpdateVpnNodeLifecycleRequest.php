<?php

namespace App\Http\Requests\Admin\V1\VpnNodes;

use App\Enums\NodeLifecycleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVpnNodeLifecycleRequest extends FormRequest
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
        return [
            'lifecycle_status' => ['required', Rule::enum(NodeLifecycleStatus::class)],
            'maintenance_mode' => ['nullable', 'boolean'],
        ];
    }
}
