<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ActivateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $device = $this->input('device', []);
        if (! is_array($device)) {
            $device = [];
        }

        // Accept Phase 2 contract aliases (customer_id, device_uuid, device_name).
        $customerCode = $this->input('customer_code') ?? $this->input('customer_id');
        $device['uuid'] = $device['uuid'] ?? $device['device_uuid'] ?? null;
        $device['name'] = $device['name'] ?? $device['device_name'] ?? null;

        $this->merge([
            'customer_code' => is_string($customerCode) ? strtoupper(trim($customerCode)) : $customerCode,
            'device' => $device,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_code' => ['required', 'string', 'max:32'],
            'activation_key' => ['required', 'string', 'max:64'],
            'device' => ['required', 'array'],
            'device.uuid' => ['required', 'string', 'uuid'],
            'device.platform' => ['required', 'string', 'in:ANDROID,IOS'],
            'device.name' => ['required', 'string', 'max:120'],
            'device.os_version' => ['nullable', 'string', 'max:60'],
            'device.app_version' => ['nullable', 'string', 'max:30'],
        ];
    }
}
