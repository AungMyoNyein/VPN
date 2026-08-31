<?php

namespace Tests\Feature\Admin\V1;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Tests\AdminTestCase;

class CustomerTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_create_customer_with_unique_code(): void
    {
        $admin = $this->createAdmin();

        $response = $this->withAdmin($admin)->postJson('/api/admin/v1/customers', [
            'name' => 'Alice Customer',
            'phone' => '+66123456789',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.customer.customer_code', 'CUST-000001')
            ->assertJsonPath('data.customer.status', 'ACTIVE');

        $second = $this->withAdmin($admin)->postJson('/api/admin/v1/customers', [
            'name' => 'Bob Customer',
        ]);

        $second->assertCreated()
            ->assertJsonPath('data.customer.customer_code', 'CUST-000002');
    }

    public function test_create_customer_with_plan_and_key(): void
    {
        $admin = $this->createAdmin();
        $plan = Plan::query()->where('code', 'STARTER_30')->firstOrFail();

        $response = $this->withAdmin($admin)->postJson('/api/admin/v1/customers', [
            'name' => 'Charlie Customer',
            'plan_id' => $plan->id,
            'generate_activation_key' => true,
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'customer',
                    'subscription',
                    'activation_key' => ['id', 'plaintext_key'],
                ],
            ]);

        $plaintext = $response->json('data.activation_key.plaintext_key');
        $this->assertMatchesRegularExpression('/^VPN-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{4}$/', $plaintext);

        $this->assertDatabaseMissing('activation_keys', ['key_hash' => $plaintext]);
    }

    public function test_update_customer(): void
    {
        $admin = $this->createAdmin();

        $create = $this->withAdmin($admin)->postJson('/api/admin/v1/customers', [
            'name' => 'Original Name',
        ])->assertCreated();

        $customerId = $create->json('data.customer.id');

        $this->withAdmin($admin)->putJson("/api/admin/v1/customers/{$customerId}", [
            'name' => 'Updated Name',
            'phone' => '+66999999999',
        ])->assertOk()
            ->assertJsonPath('data.customer.name', 'Updated Name');
    }

    public function test_change_customer_status(): void
    {
        $admin = $this->createAdmin();

        $customerId = $this->withAdmin($admin)->postJson('/api/admin/v1/customers', [
            'name' => 'Status Test',
        ])->json('data.customer.id');

        $this->withAdmin($admin)->patchJson("/api/admin/v1/customers/{$customerId}/status", [
            'status' => CustomerStatus::Suspended->value,
        ])->assertOk()
            ->assertJsonPath('data.customer.status', 'SUSPENDED');
    }

    public function test_list_and_search_customers(): void
    {
        $admin = $this->createAdmin();

        Customer::query()->create([
            'customer_code' => 'CUST-000100',
            'name' => 'Searchable Alice',
            'status' => CustomerStatus::Active,
        ]);

        $this->withAdmin($admin)
            ->getJson('/api/admin/v1/customers?search=Searchable')
            ->assertOk()
            ->assertJsonPath('data.data.0.name', 'Searchable Alice');
    }
}
