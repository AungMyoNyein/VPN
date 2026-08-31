<?php

namespace Tests\Feature\Admin\V1;

use Tests\AdminTestCase;

class PlanTest extends AdminTestCase
{
    public function test_create_plan_validation(): void
    {
        $admin = $this->createAdmin();

        $this->withAdmin($admin)->postJson('/api/admin/v1/plans', [])
            ->assertUnprocessable();

        $response = $this->withAdmin($admin)->postJson('/api/admin/v1/plans', [
            'name' => 'Test Plan',
            'code' => 'TEST_PLAN',
            'price' => 15.00,
            'currency' => 'USD',
            'duration_days' => 30,
            'max_devices' => 2,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.plan.code', 'TEST_PLAN');
    }

    public function test_duplicate_plan_code_rejected(): void
    {
        $admin = $this->createAdmin();

        $payload = [
            'name' => 'Plan A',
            'code' => 'DUPE_CODE',
            'price' => 10,
            'currency' => 'USD',
            'duration_days' => 30,
            'max_devices' => 1,
        ];

        $this->withAdmin($admin)->postJson('/api/admin/v1/plans', $payload)->assertCreated();
        $this->withAdmin($admin)->postJson('/api/admin/v1/plans', $payload)->assertUnprocessable();
    }

    public function test_update_plan(): void
    {
        $admin = $this->createAdmin();

        $planId = $this->withAdmin($admin)->postJson('/api/admin/v1/plans', [
            'name' => 'Old Name',
            'code' => 'UPDATE_ME',
            'price' => 10,
            'currency' => 'USD',
            'duration_days' => 30,
            'max_devices' => 1,
        ])->json('data.plan.id');

        $this->withAdmin($admin)->putJson("/api/admin/v1/plans/{$planId}", [
            'name' => 'New Name',
            'price' => 12,
        ])->assertOk()
            ->assertJsonPath('data.plan.name', 'New Name');
    }
}
