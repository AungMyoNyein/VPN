<?php

namespace App\Services\Subscriptions;

use App\Enums\EntitlementState;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Carbon\CarbonInterface;

class EntitlementService
{
    private const EXPIRING_SOON_DAYS = 7;

    public function effectiveState(?Subscription $subscription): EntitlementState
    {
        if ($subscription === null) {
            return EntitlementState::None;
        }

        if ($subscription->status === SubscriptionStatus::Suspended) {
            return EntitlementState::Suspended;
        }

        if ($subscription->status === SubscriptionStatus::Expired
            || $subscription->status === SubscriptionStatus::Cancelled) {
            return EntitlementState::Expired;
        }

        $now = now();

        if ($subscription->starts_at->isFuture()) {
            return EntitlementState::NotStarted;
        }

        if ($subscription->expires_at->lte($now)) {
            return EntitlementState::Expired;
        }

        if ($subscription->status !== SubscriptionStatus::Active) {
            return EntitlementState::None;
        }

        if ($subscription->expires_at->lte($now->copy()->addDays(self::EXPIRING_SOON_DAYS))) {
            return EntitlementState::ExpiringSoon;
        }

        return EntitlementState::Active;
    }

    public function effectiveMaxDevices(?Subscription $subscription): int
    {
        if ($subscription === null) {
            return 0;
        }

        $subscription->loadMissing('plan');

        return $subscription->custom_max_devices ?? $subscription->plan->max_devices;
    }

    public function isUsable(?Subscription $subscription, ?CarbonInterface $at = null): bool
    {
        if ($subscription === null) {
            return false;
        }

        $at ??= now();

        return $subscription->status === SubscriptionStatus::Active
            && $subscription->starts_at->lte($at)
            && $subscription->expires_at->gt($at);
    }
}
