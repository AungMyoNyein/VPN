<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\DeviceCredential;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Issues and validates opaque, high-entropy bearer credentials used by
 * mobile clients after activation (ADR-0008). Credentials are never
 * persisted in plaintext — only a hash plus a short lookup prefix.
 *
 * NO WireGuard / peer provisioning / IP allocation happens here.
 */
class DeviceCredentialService
{
    private const TOKEN_BYTES = 32;

    private const PREFIX_LENGTH = 8;

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Issues a new credential for the device, revoking any previously
     * active credential first (immediate rotation — at most one active
     * credential per device at a time, see device_credentials_one_active_per_device).
     *
     * @return array{credential: DeviceCredential, plaintext_token: string}
     */
    public function issue(Device $device): array
    {
        return DB::transaction(function () use ($device) {
            $this->revokeAllForDevice($device);

            $plaintext = $this->generateToken();
            $prefix = substr($plaintext, 0, self::PREFIX_LENGTH);

            $credential = DeviceCredential::query()->create([
                'device_id' => $device->id,
                'token_prefix' => $prefix,
                'token_hash' => Hash::make($plaintext),
                'issued_at' => now(),
                'expires_at' => $this->expiryFromConfig(),
            ]);

            $this->auditLogger->log(
                'device_credential.issued',
                'device',
                $device->id,
                after: ['device_id' => $device->id, 'token_prefix' => $prefix],
            );

            return ['credential' => $credential, 'plaintext_token' => $plaintext];
        });
    }

    /**
     * @return array{credential: DeviceCredential, plaintext_token: string}
     */
    public function rotate(Device $device): array
    {
        return $this->issue($device);
    }

    public function revokeAllForDevice(Device $device): void
    {
        DeviceCredential::query()
            ->where('device_id', $device->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Looks up a credential by prefix candidates + hash check, regardless
     * of revocation/expiry state. Callers that need "currently usable"
     * semantics should use findValidByPlaintext().
     */
    public function findByPlaintext(string $plaintext): ?DeviceCredential
    {
        $normalized = trim($plaintext);

        if ($normalized === '') {
            return null;
        }

        $prefix = substr($normalized, 0, self::PREFIX_LENGTH);

        $candidates = DeviceCredential::query()->where('token_prefix', $prefix)->get();

        foreach ($candidates as $candidate) {
            if (Hash::check($normalized, $candidate->token_hash)) {
                return $candidate;
            }
        }

        return null;
    }

    public function findValidByPlaintext(string $plaintext): ?DeviceCredential
    {
        $credential = $this->findByPlaintext($plaintext);

        if ($credential === null || $credential->revoked_at !== null) {
            return null;
        }

        if ($credential->expires_at !== null && $credential->expires_at->isPast()) {
            return null;
        }

        return $credential;
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    private function expiryFromConfig(): ?Carbon
    {
        $days = config('activation.device_credential_ttl_days');

        return $days ? now()->addDays((int) $days) : null;
    }
}
