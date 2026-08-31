<?php

namespace App\Http\Requests\Admin\V1\VpnNodes;

use App\Enums\NodeHealthStatus;
use App\Enums\NodeLifecycleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVpnNodeRequest extends FormRequest
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
        $nodeId = $this->route('vpn_node')?->id;

        return [
            'location_id' => ['sometimes', 'exists:locations,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'hostname' => ['sometimes', 'string', 'max:255', Rule::unique('vpn_nodes', 'hostname')->ignore($nodeId)],
            'public_endpoint' => ['sometimes', 'string', 'max:255'],
            'vpn_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'public_key' => ['nullable', 'string', 'max:255'],
            'capacity_users' => ['sometimes', 'integer', 'min:1'],
            'health_status' => ['nullable', Rule::enum(NodeHealthStatus::class)],
            'maintenance_mode' => ['nullable', 'boolean'],
            'weight' => ['nullable', 'integer', 'min:0'],
            'adapter_type' => ['nullable', 'string', 'in:fake,remote'],
            'agent_endpoint' => ['nullable', 'string', 'max:255'],
            'agent_version' => ['nullable', 'string', 'max:50'],
            'wireguard_interface' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
