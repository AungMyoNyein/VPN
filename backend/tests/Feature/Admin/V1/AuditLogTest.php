<?php

namespace Tests\Feature\Admin\V1;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Services\Audit\AuditLogger;
use Tests\AdminTestCase;

class AuditLogTest extends AdminTestCase
{
    public function test_audit_record_created_on_customer_create(): void
    {
        $admin = $this->createAdmin();

        $this->withAdmin($admin)->postJson('/api/admin/v1/customers', [
            'name' => 'Audited Customer',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'customer.created',
            'target_type' => 'customer',
            'actor_type' => 'ADMIN',
            'actor_id' => $admin->id,
        ]);
    }

    public function test_audit_logger_redacts_secrets(): void
    {
        $admin = $this->createAdmin();

        app(AuditLogger::class)->log(
            'test.secret',
            'test',
            1,
            after: ['password' => 'secret123', 'key_hash' => 'hash', 'name' => 'visible'],
            actor: $admin,
        );

        $log = AuditLog::query()->where('action', 'test.secret')->firstOrFail();

        $this->assertSame('[REDACTED]', $log->after_data['password']);
        $this->assertSame('[REDACTED]', $log->after_data['key_hash']);
        $this->assertSame('visible', $log->after_data['name']);
    }

    public function test_audit_logs_api(): void
    {
        $admin = $this->createAdmin();

        Customer::query()->create([
            'customer_code' => 'CUST-000001',
            'name' => 'Audit List',
            'status' => \App\Enums\CustomerStatus::Active,
        ]);

        app(AuditLogger::class)->log('customer.created', 'customer', 1, actor: $admin);

        $this->withAdmin($admin)->getJson('/api/admin/v1/audit-logs')
            ->assertOk()
            ->assertJsonStructure(['data' => ['data']]);
    }
}
