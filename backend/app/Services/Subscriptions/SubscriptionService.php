<?php

namespace App\Services\Subscriptions;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaymentService;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PaymentService $paymentService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, AdminUser $actor, bool $audit = true): Subscription
    {
        $plan = Plan::query()->findOrFail($data['plan_id']);
        $startsAt = isset($data['starts_at']) ? now()->parse($data['starts_at']) : now();
        $expiresAt = isset($data['expires_at'])
            ? now()->parse($data['expires_at'])
            : $startsAt->copy()->addDays($plan->duration_days);

        $subscription = Subscription::query()->create([
            'customer_id' => $data['customer_id'],
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'source' => $data['source'] ?? SubscriptionSource::Manual->value,
            'auto_renew' => $data['auto_renew'] ?? false,
            'custom_max_devices' => $data['custom_max_devices'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        if ($audit) {
            $this->auditLogger->log(
                'subscription.created',
                'subscription',
                $subscription->id,
                after: $subscription->toArray(),
                actor: $actor,
            );
        }

        return $subscription->load('plan');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function renew(Subscription $subscription, array $data, AdminUser $actor): Subscription
    {
        return DB::transaction(function () use ($subscription, $data, $actor): Subscription {
            $plan = $subscription->plan;
            $mode = $data['mode'] ?? 'extend';
            $before = $subscription->toArray();

            $startsAt = $subscription->starts_at;
            $expiresAt = match ($mode) {
                'from_now' => now()->copy()->addDays($plan->duration_days),
                'custom' => now()->parse($data['expires_at']),
                default => $subscription->expires_at->copy()->addDays($plan->duration_days),
            };

            if ($mode === 'from_now') {
                $startsAt = now();
            }

            $subscription->update([
                'status' => SubscriptionStatus::Active,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
            ]);

            if (! empty($data['payment'])) {
                $this->paymentService->create(array_merge($data['payment'], [
                    'customer_id' => $subscription->customer_id,
                    'subscription_id' => $subscription->id,
                ]), $actor, audit: false);
            }

            $this->auditLogger->log(
                'subscription.renewed',
                'subscription',
                $subscription->id,
                before: $before,
                after: $subscription->fresh()->toArray(),
                actor: $actor,
            );

            return $subscription->fresh()->load('plan');
        });
    }

    public function renewCustomer(Customer $customer, array $data, AdminUser $actor): Subscription
    {
        $subscription = $customer->subscriptions()
            ->where('status', SubscriptionStatus::Active)
            ->orderByDesc('expires_at')
            ->first();

        if ($subscription === null) {
            if (empty($data['plan_id'])) {
                throw new \InvalidArgumentException('No active subscription and plan_id is required.');
            }

            return $this->create([
                'customer_id' => $customer->id,
                'plan_id' => $data['plan_id'],
                'source' => SubscriptionSource::Crm->value,
            ], $actor);
        }

        return $this->renew($subscription, $data, $actor);
    }
}
