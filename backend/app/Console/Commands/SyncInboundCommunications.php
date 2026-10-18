<?php

namespace App\Console\Commands;

use App\Integrations\EmailGateway;
use App\Models\Communication;
use Illuminate\Console\Command;

/**
 * Pull the mocked mailbox's replies into the communication history (module 03 slice S2,
 * spec 11 §Business rules 7).
 *
 * Inbound is never pushed and nothing here runs on a timer in this phase — automatic
 * scheduling belongs to the follow-up module — so the demo (and the test suite) runs this by
 * hand: `php artisan communication:sync-inbound`.
 */
class SyncInboundCommunications extends Command
{
    protected $signature = 'communication:sync-inbound';

    protected $description = 'Pull the mocked mailbox\'s replies into the communication history';

    public function handle(EmailGateway $gateway): int
    {
        $recorded = Communication::pullInbound($gateway);

        $this->info(sprintf(
            '%s %s recorded from the %s mailbox.',
            $recorded,
            $recorded === 1 ? 'reply' : 'replies',
            $gateway->source(),
        ));

        return self::SUCCESS;
    }
}
