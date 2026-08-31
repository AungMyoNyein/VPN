<?php

namespace App\Http\Requests\Admin\V1\Customers;

use Illuminate\Foundation\Http\FormRequest;

class GenerateActivationKeyRequest extends FormRequest
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
            'max_activations' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
