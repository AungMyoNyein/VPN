<?php

namespace App\Services\Customers;

use App\Enums\CustomerStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Services\ActivationKeys\ActivationKeyService;
use App\Services\Audit\AuditLogger;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function __construct(
        private readonly CustomerCodeGenerator $codeGenerator,
        private readonly SubscriptionService $subscriptionService,
        private readonly ActivationKeyService $activationKeyService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{customer: Customer, subscription?: \App\Models\Subscription, activation_key?: array{id: int, plaintext_key: string}}
     */
    public function create(array $data, AdminUser $actor): array
    {
        return DB::transaction(function () use ($data, $actor): array {
            $customer = Customer::query()->create([
                'customer_code' => $this->codeGenerator->generate(),
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'status' => CustomerStatus::Active,
                'notes' => $data['notes'] ?? null,
            ]);

            $result = ['customer' => $customer->fresh()];

            if (! empty($data['plan_id'])) {
                $subscription = $this->subscriptionService->create([
                    'customer_id' => $customer->id,
                    'plan_id' => $data['plan_id'],
                    'source' => SubscriptionSource::Crm->value,
                    'auto_renew' => $data['auto_renew'] ?? false,
                ], $actor, audit: true);

                $result['subscription'] = $subscription;
            }

            if (! empty($data['generate_activation_key'])) {
                $keyResult = $this->activationKeyService->generate($customer, $actor, [
                    'max_activations' => $data['key_max_activations'] ?? 1,
                    'expires_at' => $data['key_expires_at'] ?? null,
                ], audit: true);

                $result['activation_key'] = [
                    'id' => $keyResult['key']->id,
                    'plaintext_key' => $keyResult['plaintext_key'],
                ];
            }

            $this->auditLogger->log(
                'customer.created',
                'customer',
                $customer->id,
                after: $customer->toArray(),
                actor: $actor,
            );

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data, AdminUser $actor): Customer
    {
        $before = $customer->toArray();
        $updates = [];

        foreach (['name', 'phone', 'email', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if ($updates !== []) {
            $customer->update($updates);
        }

        $this->auditLogger->log(
            'customer.updated',
            'customer',
            $customer->id,
            before: $before,
            after: $customer->fresh()->toArray(),
            actor: $actor,
        );

        return $customer->fresh();
    }

    public function changeStatus(Customer $customer, CustomerStatus $status, AdminUser $actor): Customer
    {
        $before = $customer->toArray();
        $customer->update(['status' => $status]);

        $this->auditLogger->log(
            'customer.status_changed',
            'customer',
            $customer->id,
            before: $before,
            after: $customer->fresh()->toArray(),
            actor: $actor,
        );

        return $customer->fresh();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Customer::query()->orderByDesc('id');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('customer_code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }
}
