<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: device credentials become the system of record for "does this
 * device have a valid bearer credential" — devices.device_token_hash is
 * deprecated in favor of this table (kept, nullable, unused going forward;
 * DeviceService still clears it defensively on revoke for Phase 1 compat).
 *
 * Forward-only migration, applied after the Phase 1 CRM core migrations.
 * Existing unique constraint on devices(customer_id, device_uuid) is
 * untouched — see docs/DATABASE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('token_prefix', 16);
            $table->string('token_hash');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('device_id');
            $table->index('token_prefix');
        });

        // At most one active (non-revoked) credential per device.
        // Partial unique indexes are supported by SQLite (tests) and
        // PostgreSQL (production) with identical syntax; MySQL is not a
        // target database for this project (see docs/DATABASE.md).
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX device_credentials_one_active_per_device '.
                'ON device_credentials (device_id) WHERE revoked_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_credentials');
    }
};
