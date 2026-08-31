<?php

namespace App\Services\Customers;

use App\Models\CustomerCodeSequence;
use Illuminate\Support\Facades\DB;

class CustomerCodeGenerator
{
    private const PREFIX = 'CUST-';

    public function generate(): string
    {
        return DB::transaction(function (): string {
            $sequence = CustomerCodeSequence::query()->lockForUpdate()->first();

            if ($sequence === null) {
                $sequence = CustomerCodeSequence::query()->create(['last_value' => 0]);
                $sequence = CustomerCodeSequence::query()->lockForUpdate()->findOrFail($sequence->id);
            }

            $next = $sequence->last_value + 1;
            $sequence->update(['last_value' => $next]);

            return self::PREFIX.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        });
    }
}
