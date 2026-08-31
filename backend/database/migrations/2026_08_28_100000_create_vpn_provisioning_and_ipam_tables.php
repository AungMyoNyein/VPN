<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: Control Plane, IPAM and Peer Provisioning foundation.
 * Creates vpn_ip_pools, vpn_peers, vpn_ip_allocations, and provisioning_operations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_nodes', function (Blueprint $table) {
            $table->boolean('draining')->default(false)->after('maintenance_mode');
        });

        Schema::create('vpn_ip_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained('vpn_nodes')->cascadeOnDelete();
            $table->string('network'); // e.g. 10.200.20.0/24
            $table->unsignedInteger('prefix_length'); // e.g. 24
            $table->string('gateway'); // e.g. 10.200.20.1
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['node_id', 'active']);
        });

        Schema::create('vpn_peers', function (Blueprint $table) {
            $table->id();
            $table->string('peer_code')->unique(); // e.g. WG-PEER-001
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('node_id')->constrained('vpn_nodes')->restrictOnDelete();
            $table->string('public_key');
            $table->string('assigned_ip'); // e.g. 10.200.20.2
            $table->string('status', 20)->default('PENDING'); // PENDING, ACTIVE, ERROR, REVOKING, REVOKED
            $table->string('failure_reason')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status']);
            $table->index(['node_id', 'status']);
            $table->index('status');
            $table->index('public_key');
        });

        Schema::create('vpn_ip_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained('vpn_ip_pools')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('vpn_peer_id')->constrained('vpn_peers')->cascadeOnDelete();
            $table->string('ip_address'); // e.g. 10.200.20.2
            $table->string('status', 20)->default('ALLOCATED'); // ALLOCATED, RELEASED
            $table->timestamp('allocated_at')->useCurrent();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['pool_id', 'status']);
            $table->index('vpn_peer_id');
            $table->index('device_id');
            $table->index('status');
        });

        Schema::create('provisioning_operations', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->index();
            $table->foreignId('peer_id')->nullable()->constrained('vpn_peers')->nullOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('operation_type', 20); // PROVISION, REVOKE
            $table->string('status', 20)->default('PENDING'); // PENDING, RUNNING, SUCCEEDED, FAILED
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamps();

            $table->index(['idempotency_key', 'device_id']);
            $table->index(['status', 'operation_type']);
        });

        // Partial unique constraints for race-safety (PostgreSQL & SQLite)
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX vpn_ip_allocations_active_ip_unique '.
                'ON vpn_ip_allocations (ip_address) WHERE released_at IS NULL'
            );

            DB::statement(
                'CREATE UNIQUE INDEX vpn_peers_one_active_per_device '.
                'ON vpn_peers (device_id) WHERE status IN (\'PENDING\', \'ACTIVE\', \'REVOKING\')'
            );

            DB::statement(
                'CREATE UNIQUE INDEX vpn_peers_active_public_key_unique '.
                'ON vpn_peers (public_key) WHERE status IN (\'PENDING\', \'ACTIVE\', \'REVOKING\')'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_operations');
        Schema::dropIfExists('vpn_ip_allocations');
        Schema::dropIfExists('vpn_peers');
        Schema::dropIfExists('vpn_ip_pools');

        if (Schema::hasColumn('vpn_nodes', 'draining')) {
            Schema::table('vpn_nodes', function (Blueprint $table) {
                $table->dropColumn('draining');
            });
        }
    }
};
