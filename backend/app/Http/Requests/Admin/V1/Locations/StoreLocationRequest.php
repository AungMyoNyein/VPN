<?php

namespace App\Http\Requests\Admin\V1\Locations;

use Illuminate\Foundation\Http\FormRequest;

class StoreLocationRequest extends FormRequest
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
            'country_code' => ['required', 'string', 'size:2'],
            'country_name' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
