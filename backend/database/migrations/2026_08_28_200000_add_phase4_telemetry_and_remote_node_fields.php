<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: Real WireGuard Node & Telemetry.
 * Adds adapter_type, agent_endpoint, agent_version, wireguard_interface, and telemetry counters to vpn_nodes and vpn_peers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_nodes', function (Blueprint $table) {
            $table->string('adapter_type', 20)->default('fake')->after('draining'); // fake | remote
            $table->string('agent_endpoint')->nullable()->after('adapter_type');
            $table->string('agent_version', 50)->nullable()->after('agent_endpoint');
            $table->string('wireguard_interface', 30)->default('wg0')->after('agent_version');
            $table->timestamp('last_synced_at')->nullable()->after('last_heartbeat_at');
            $table->unsignedBigInteger('latest_rx_bytes')->default(0)->after('last_synced_at');
            $table->unsignedBigInteger('latest_tx_bytes')->default(0)->after('latest_rx_bytes');

            $table->index(['adapter_type', 'health_status']);
        });

        Schema::table('vpn_peers', function (Blueprint $table) {
            $table->timestamp('latest_handshake_at')->nullable()->after('revoked_at');
            $table->unsignedBigInteger('rx_bytes')->default(0)->after('latest_handshake_at');
            $table->unsignedBigInteger('tx_bytes')->default(0)->after('rx_bytes');
            $table->timestamp('last_synced_at')->nullable()->after('tx_bytes');

            $table->index('latest_handshake_at');
        });
    }

    public function down(): void
    {
        Schema::table('vpn_peers', function (Blueprint $table) {
            $table->dropIndex(['latest_handshake_at']);
            $table->dropColumn(['latest_handshake_at', 'rx_bytes', 'tx_bytes', 'last_synced_at']);
        });

        Schema::table('vpn_nodes', function (Blueprint $table) {
            $table->dropIndex(['adapter_type', 'health_status']);
            $table->dropColumn([
                'adapter_type',
                'agent_endpoint',
                'agent_version',
                'wireguard_interface',
                'last_synced_at',
                'latest_rx_bytes',
                'latest_tx_bytes',
            ]);
        });
    }
};
