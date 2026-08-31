<?php

namespace Tests\Unit;

use App\Support\ApiResponse;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    public function test_success_envelope_shape(): void
    {
        $response = ApiResponse::success(['hello' => 'world'], ['source' => 'unit']);
        $payload = $response->getData(true);

        $this->assertSame(['hello' => 'world'], $payload['data']);
        $this->assertSame('unit', $payload['meta']['source']);
        $this->assertArrayHasKey('request_id', $payload['meta']);
    }

    public function test_error_envelope_shape(): void
    {
        $response = ApiResponse::error('SUBSCRIPTION_EXPIRED', 'Subscription has expired', 403);
        $payload = $response->getData(true);

        $this->assertSame('SUBSCRIPTION_EXPIRED', $payload['error']['code']);
        $this->assertSame('Subscription has expired', $payload['error']['message']);
        $this->assertArrayHasKey('request_id', $payload['error']);
        $this->assertSame(403, $response->getStatusCode());
    }
}
