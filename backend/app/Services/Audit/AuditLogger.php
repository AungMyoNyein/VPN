<?php

namespace App\Services\Audit;

use App\Models\AdminUser;
use App\Models\AuditLog;

class AuditLogger
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'key_hash',
        'plaintext_key',
        'activation_key',
        'device_token_hash',
        'token',
        'token_hash',
        'plaintext_token',
        'device_credential',
        'credential',
        'bearer',
        'secret',
        'remember_token',
    ];

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($lower === $sensitive) {
                return true;
            }
        }

        // Exact "key" only — do not redact key_prefix / foreign key ids.
        return $lower === 'key';
    }

    public function log(
        string $action,
        string $targetType,
        string|int $targetId,
        ?array $before = null,
        ?array $after = null,
        ?AdminUser $actor = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_type' => $actor ? 'ADMIN' : 'SYSTEM',
            'actor_id' => $actor?->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => (string) $targetId,
            'before_data' => $this->sanitize($before),
            'after_data' => $this->sanitize($after),
            'source_ip' => request()->ip(),
            'request_id' => request()->attributes->get('request_id'),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    private function sanitize(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $sanitized = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }
}
