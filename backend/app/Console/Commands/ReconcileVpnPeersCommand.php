<?php

namespace App\Console\Commands;

use App\Services\Vpn\ReconciliationService;
use Illuminate\Console\Command;

class ReconcileVpnPeersCommand extends Command
{
    protected $signature = 'vpn:reconcile {--sync-telemetry : Also sync WireGuard telemetry metrics}';
    protected $description = 'Reconcile VPN peers and provisioning operations against the control-plane';

    public function handle(ReconciliationService $reconciliationService): int
    {
        $this->info('Starting VPN peer reconciliation pass...');
        $stats = $reconciliationService->reconcile();

        $this->info(sprintf(
            'Reconciliation complete: %d provisions reconciled, %d revocations reconciled, %d errors.',
            $stats['reconciled_provisions'],
            $stats['reconciled_revocations'],
            $stats['errors']
        ));

        if ($this->option('sync-telemetry')) {
            $this->info('Synchronizing WireGuard telemetry...');
            $tStats = $reconciliationService->syncTelemetry();
            $this->info(sprintf('Telemetry synchronized: %d nodes, %d peers.', $tStats['nodes_synced'], $tStats['peers_synced']));
        }

        return Command::SUCCESS;
    }
}
