<?php

namespace App\Http\Requests\Admin\V1\Plans;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:plans,code'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'duration_days' => ['required', 'integer', 'min:1'],
            'max_devices' => ['required', 'integer', 'min:1'],
            'speed_limit_mbps' => ['nullable', 'integer', 'min:1'],
            'traffic_limit_bytes' => ['nullable', 'integer', 'min:1'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
