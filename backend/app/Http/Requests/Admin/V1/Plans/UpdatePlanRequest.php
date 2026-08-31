<?php

namespace App\Http\Requests\Admin\V1\Plans;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlanRequest extends FormRequest
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
        $planId = $this->route('plan')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('plans', 'code')->ignore($planId)],
            'description' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'duration_days' => ['sometimes', 'integer', 'min:1'],
            'max_devices' => ['sometimes', 'integer', 'min:1'],
            'speed_limit_mbps' => ['nullable', 'integer', 'min:1'],
            'traffic_limit_bytes' => ['nullable', 'integer', 'min:1'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
