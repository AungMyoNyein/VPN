<?php

namespace App\Http\Requests\Admin\V1\VpnNodes;

use App\Enums\NodeHealthStatus;
use App\Enums\NodeLifecycleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVpnNodeRequest extends FormRequest
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
            'location_id' => ['required', 'exists:locations,id'],
            'name' => ['required', 'string', 'max:255'],
            'hostname' => ['required', 'string', 'max:255', 'unique:vpn_nodes,hostname'],
            'public_endpoint' => ['required', 'string', 'max:255'],
            'vpn_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'public_key' => ['nullable', 'string', 'max:255'],
            'capacity_users' => ['required', 'integer', 'min:1'],
            'health_status' => ['nullable', Rule::enum(NodeHealthStatus::class)],
            'lifecycle_status' => ['nullable', Rule::enum(NodeLifecycleStatus::class)],
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
