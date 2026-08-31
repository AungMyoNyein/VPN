<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\AdminUser;
use App\Models\Payment;
use App\Services\Audit\AuditLogger;

class PaymentService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, AdminUser $actor, bool $audit = true): Payment
    {
        $payment = Payment::query()->create([
            'customer_id' => $data['customer_id'],
            'subscription_id' => $data['subscription_id'] ?? null,
            'payment_method' => $data['payment_method'],
            'external_reference' => $data['external_reference'] ?? null,
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'status' => $data['status'] ?? PaymentStatus::Paid->value,
            'paid_at' => $data['paid_at'] ?? now(),
            'notes' => $data['notes'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'created_by' => $actor->id,
        ]);

        if ($audit) {
            $this->auditLogger->log(
                'payment.created',
                'payment',
                $payment->id,
                after: $payment->toArray(),
                actor: $actor,
            );
        }

        return $payment;
    }
}
