<?php

namespace Tests\Unit\Services;

use App\Services\Customers\CustomerCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_sequential_codes(): void
    {
        $generator = app(CustomerCodeGenerator::class);

        $this->assertSame('CUST-000001', $generator->generate());
        $this->assertSame('CUST-000002', $generator->generate());
    }
}
