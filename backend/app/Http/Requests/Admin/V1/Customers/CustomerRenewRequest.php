<?php

namespace App\Http\Requests\Admin\V1\Customers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRenewRequest extends FormRequest
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
            'mode' => ['nullable', Rule::in(['extend', 'from_now', 'custom'])],
            'plan_id' => ['nullable', 'exists:plans,id'],
            'expires_at' => ['required_if:mode,custom', 'nullable', 'date'],
            'payment' => ['nullable', 'array'],
            'payment.payment_method' => ['required_with:payment', 'string'],
            'payment.amount' => ['required_with:payment', 'numeric', 'min:0'],
            'payment.currency' => ['required_with:payment', 'string', 'size:3'],
        ];
    }
}
