<?php

namespace Tests\Feature\Admin\V1;

use App\Enums\ActivationKeyStatus;
use App\Models\ActivationKey;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Services\ActivationKeys\ActivationKeyService;
use Tests\AdminTestCase;

class ActivationKeyTest extends AdminTestCase
{
    public function test_key_hash_stored_not_plaintext(): void
    {
        $admin = $this->createAdmin();
        $customer = Customer::query()->create([
            'customer_code' => 'CUST-000001',
            'name' => 'Key Customer',
            'status' => \App\Enums\CustomerStatus::Active,
        ]);

        $result = app(ActivationKeyService::class)->generate($customer, $admin);
        $plaintext = $result['plaintext_key'];

        $this->assertDatabaseHas('activation_keys', [
            'id' => $result['key']->id,
            'key_prefix' => $result['key']->key_prefix,
        ]);

        $stored = ActivationKey::query()->findOrFail($result['key']->id);
        $this->assertNotSame($plaintext, $stored->key_hash);
        $this->assertTrue(password_verify(strtoupper(str_replace(' ', '', $plaintext)), $stored->key_hash)
            || \Illuminate\Support\Facades\Hash::check(strtoupper(str_replace(' ', '', $plaintext)), $stored->key_hash));
    }

    public function test_generate_via_api_returns_plaintext_once(): void
    {
        $admin = $this->createAdmin();
        $customer = Customer::query()->create([
            'customer_code' => 'CUST-000002',
            'name' => 'API Key',
            'status' => \App\Enums\CustomerStatus::Active,
        ]);

        $response = $this->withAdmin($admin)->postJson("/api/admin/v1/customers/{$customer->id}/activation-keys");

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['activation_key', 'plaintext_key']]);

        $plaintext = $response->json('data.plaintext_key');

        $this->withAdmin($admin)->getJson('/api/admin/v1/activation-keys/'.$response->json('data.activation_key.id'))
            ->assertOk()
            ->assertJsonMissing(['plaintext_key' => $plaintext]);
    }

    public function test_revoke_activation_key(): void
    {
        $admin = $this->createAdmin();
        $customer = Customer::query()->create([
            'customer_code' => 'CUST-000003',
            'name' => 'Revoke Key',
            'status' => \App\Enums\CustomerStatus::Active,
        ]);

        $key = app(ActivationKeyService::class)->generate($customer, $admin)['key'];

        $this->withAdmin($admin)->postJson("/api/admin/v1/activation-keys/{$key->id}/revoke")
            ->assertOk()
            ->assertJsonPath('data.activation_key.status', ActivationKeyStatus::Revoked->value);
    }

    public function test_audit_does_not_contain_plaintext_key(): void
    {
        $admin = $this->createAdmin();
        $customer = Customer::query()->create([
            'customer_code' => 'CUST-000004',
            'name' => 'Audit Key',
            'status' => \App\Enums\CustomerStatus::Active,
        ]);

        $plaintext = app(ActivationKeyService::class)->generate($customer, $admin)['plaintext_key'];

        $log = AuditLog::query()->where('action', 'activation_key.generated')->latest('id')->first();

        $this->assertNotNull($log);
        $encoded = json_encode($log->toArray());
        $this->assertStringNotContainsString($plaintext, $encoded);
        $this->assertStringNotContainsString('plaintext', strtolower($encoded));
    }
}
