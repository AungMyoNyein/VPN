<?php

namespace App\Http\Requests\Admin\V1\Customers;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
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
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'plan_id' => ['nullable', 'exists:plans,id'],
            'auto_renew' => ['nullable', 'boolean'],
            'generate_activation_key' => ['nullable', 'boolean'],
            'key_max_activations' => ['nullable', 'integer', 'min:1'],
            'key_expires_at' => ['nullable', 'date'],
        ];
    }
}
