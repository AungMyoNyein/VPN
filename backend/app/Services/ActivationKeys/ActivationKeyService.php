<?php

namespace App\Services\ActivationKeys;

use App\Enums\ActivationKeyStatus;
use App\Models\ActivationKey;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ActivationKeyService
{
    private const CHARSET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const SEGMENT_LENGTH = 4;

    private const SEGMENTS = 3;

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{key: ActivationKey, plaintext_key: string}
     */
    public function generate(Customer $customer, AdminUser $actor, array $options = [], bool $audit = true): array
    {
        $plaintext = $this->generatePlaintextKey();
        $normalized = $this->normalizeKey($plaintext);
        $prefix = $this->extractPrefix($normalized);

        $key = ActivationKey::query()->create([
            'customer_id' => $customer->id,
            'key_prefix' => $prefix,
            'key_hash' => Hash::make($normalized),
            'status' => ActivationKeyStatus::Active,
            'max_activations' => $options['max_activations'] ?? 1,
            'activation_count' => 0,
            'expires_at' => $options['expires_at'] ?? null,
            'created_by' => $actor->id,
        ]);

        if ($audit) {
            $this->auditLogger->log(
                'activation_key.generated',
                'activation_key',
                $key->id,
                after: array_merge($key->toArray(), ['key_prefix' => $prefix]),
                actor: $actor,
            );
        }

        return ['key' => $key, 'plaintext_key' => $plaintext];
    }

    public function revoke(ActivationKey $key, AdminUser $actor): ActivationKey
    {
        $before = $key->toArray();

        $key->update([
            'status' => ActivationKeyStatus::Revoked,
            'revoked_at' => now(),
        ]);

        $this->auditLogger->log(
            'activation_key.revoked',
            'activation_key',
            $key->id,
            before: $before,
            after: $key->fresh()->toArray(),
            actor: $actor,
        );

        return $key->fresh();
    }

    public function suspend(ActivationKey $key, AdminUser $actor): ActivationKey
    {
        $before = $key->toArray();

        $key->update(['status' => ActivationKeyStatus::Suspended]);

        $this->auditLogger->log(
            'activation_key.suspended',
            'activation_key',
            $key->id,
            before: $before,
            after: $key->fresh()->toArray(),
            actor: $actor,
        );

        return $key->fresh();
    }

    /**
     * Verifies a plaintext activation key and returns the matching row.
     *
     * When $customer is provided, only keys belonging to that customer are
     * considered — this lets ActivationService reject "right key, wrong
     * customer" the same way as "wrong key" (anti-enumeration).
     */
    public function verify(string $plaintext, ?Customer $customer = null): ?ActivationKey
    {
        $normalized = $this->normalizeKey($plaintext);
        $prefix = $this->extractPrefix($normalized);

        $candidates = ActivationKey::query()
            ->where('key_prefix', $prefix)
            ->when($customer !== null, fn ($query) => $query->where('customer_id', $customer->id))
            ->whereIn('status', [
                ActivationKeyStatus::Active,
                ActivationKeyStatus::Used,
                ActivationKeyStatus::Suspended,
                ActivationKeyStatus::Revoked,
                ActivationKeyStatus::Expired,
            ])
            ->get();

        foreach ($candidates as $key) {
            if (Hash::check($normalized, $key->key_hash)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * True when the key's own status/expiry does not preclude use.
     * Does NOT check activation_count vs max_activations — a key at its
     * limit may still be usable for an idempotent same-device re-activation,
     * which is a decision left to ActivationService.
     */
    public function isKeyUsable(ActivationKey $key): bool
    {
        if (in_array($key->status, [
            ActivationKeyStatus::Suspended,
            ActivationKeyStatus::Revoked,
            ActivationKeyStatus::Expired,
        ], true)) {
            return false;
        }

        if ($key->expires_at !== null && $key->expires_at->lte(now())) {
            return false;
        }

        return true;
    }

    private function generatePlaintextKey(): string
    {
        $segments = [];

        for ($i = 0; $i < self::SEGMENTS; $i++) {
            $segments[] = $this->randomSegment();
        }

        return 'VPN-'.implode('-', $segments);
    }

    private function randomSegment(): string
    {
        $segment = '';
        $max = strlen(self::CHARSET) - 1;

        for ($i = 0; $i < self::SEGMENT_LENGTH; $i++) {
            $segment .= self::CHARSET[random_int(0, $max)];
        }

        return $segment;
    }

    private function normalizeKey(string $key): string
    {
        return Str::upper(str_replace(' ', '', trim($key)));
    }

    private function extractPrefix(string $normalized): string
    {
        $parts = explode('-', $normalized);

        return count($parts) >= 2 ? $parts[0].'-'.$parts[1] : substr($normalized, 0, 8);
    }
}
