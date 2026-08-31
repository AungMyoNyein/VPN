<?php

namespace App\Http\Requests\Admin\V1\Subscriptions;

use App\Enums\SubscriptionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubscriptionRequest extends FormRequest
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
            'status' => ['sometimes', Rule::enum(SubscriptionStatus::class)],
            'starts_at' => ['sometimes', 'date'],
            'expires_at' => ['sometimes', 'date'],
            'auto_renew' => ['nullable', 'boolean'],
            'custom_max_devices' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
