<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\VpnProtocol;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VpnProvisionRequest extends FormRequest
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
        $protocol = strtolower((string) $this->input('protocol', VpnProtocol::Wireguard->value));

        return [
            'protocol' => ['nullable', 'string', Rule::in(VpnProtocol::values())],
            'location_id' => ['nullable', 'integer'],
            'client_public_key' => [
                Rule::requiredIf($protocol === VpnProtocol::Wireguard->value),
                'nullable',
                'string',
                'size:44',
            ],
            'client_uuid' => [
                Rule::requiredIf($protocol === VpnProtocol::Vless->value),
                'nullable',
                'string',
                'uuid',
            ],
        ];
    }
}
