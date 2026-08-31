<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\SubscriptionExpiryService;
use Illuminate\Console\Command;

class ProcessExpiredSubscriptionsCommand extends Command
{
    protected $signature = 'vpn:process-expired-subscriptions';
    protected $description = 'Process expired subscriptions and revoke VPN access for unentitled devices';

    public function handle(SubscriptionExpiryService $expiryService): int
    {
        $this->info('Processing expired subscriptions...');
        $stats = $expiryService->processExpired();

        $this->info(sprintf(
            'Processed %d expired subscriptions, revoked %d active VPN peers.',
            $stats['expired_subscriptions'],
            $stats['revoked_peers']
        ));

        return Command::SUCCESS;
    }
}
