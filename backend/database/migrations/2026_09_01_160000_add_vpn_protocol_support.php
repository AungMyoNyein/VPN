<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds multi-protocol support (WireGuard + VLESS) to VPN nodes and peers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_nodes', function (Blueprint $table) {
            $table->json('supported_protocols')->nullable()->after('wireguard_interface');
            $table->unsignedSmallInteger('vless_port')->nullable()->after('vpn_port');
            $table->json('protocol_config')->nullable()->after('supported_protocols');
        });

        Schema::table('vpn_peers', function (Blueprint $table) {
            $table->string('protocol', 20)->default('wireguard')->after('node_id');
            $table->string('client_identity')->nullable()->after('public_key');
        });

        // Backfill existing rows
        DB::table('vpn_nodes')->whereNull('supported_protocols')->update([
            'supported_protocols' => json_encode(['wireguard']),
        ]);

        DB::table('vpn_peers')->update(['protocol' => 'wireguard']);
    }

    public function down(): void
    {
        Schema::table('vpn_peers', function (Blueprint $table) {
            $table->dropColumn(['protocol', 'client_identity']);
        });

        Schema::table('vpn_nodes', function (Blueprint $table) {
            $table->dropColumn(['supported_protocols', 'vless_port', 'protocol_config']);
        });
    }
};
