<?php

namespace Tests\Feature\Admin\V1;

use App\Models\AuditLog;
use App\Models\Customer;
use Tests\AdminTestCase;

class PaymentTest extends AdminTestCase
{
    public function test_create_payment(): void
    {
        $admin = $this->createAdmin();
        $customer = Customer::query()->create([
            'customer_code' => 'CUST-000001',
            'name' => 'Pay Customer',
            'status' => \App\Enums\CustomerStatus::Active,
        ]);

        $response = $this->withAdmin($admin)->postJson('/api/admin/v1/payments', [
            'customer_id' => $customer->id,
            'payment_method' => 'CASH',
            'amount' => 29.99,
            'currency' => 'USD',
            'notes' => 'Walk-in payment',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment.status', 'PAID')
            ->assertJsonPath('data.payment.amount', '29.99');
    }

    public function test_customer_nested_payment(): void
    {
        $admin = $this->createAdmin();
        $customer = Customer::query()->create([
            'customer_code' => 'CUST-000002',
            'name' => 'Nested Pay',
            'status' => \App\Enums\CustomerStatus::Active,
        ]);

        $this->withAdmin($admin)->postJson("/api/admin/v1/customers/{$customer->id}/payments", [
            'payment_method' => 'BANK_TRANSFER',
            'amount' => 50,
            'currency' => 'USD',
        ])->assertCreated();
    }
}
