<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_returns_canonical_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['status', 'service', 'phase', 'api_version'],
                'meta' => ['request_id'],
            ])
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.phase', 0)
            ->assertJsonPath('data.api_version', 'v1');

        $this->assertNotEmpty($response->headers->get('X-Request-ID'));
    }

    public function test_health_propagates_client_request_id(): void
    {
        $id = '11111111-2222-3333-4444-555555555555';

        $response = $this->withHeader('X-Request-ID', $id)
            ->getJson('/api/v1/health');

        $response->assertOk();
        $this->assertSame($id, $response->headers->get('X-Request-ID'));
        $response->assertJsonPath('meta.request_id', $id);
    }
}
