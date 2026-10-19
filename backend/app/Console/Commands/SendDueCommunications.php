<?php

namespace App\Console\Commands;

use App\Integrations\EmailGateway;
use App\Models\Communication;
use Illuminate\Console\Command;

/**
 * Send the scheduled emails whose moment has come (module 04 slice S2, spec 12 §6).
 *
 * Nothing runs on a timer in this phase, so the demo (and the test suite) dispatches by hand —
 * exactly like the mocked inbound sync: `php artisan communication:send-due`. A second run
 * sends nothing, because what went out is no longer scheduled.
 */
class SendDueCommunications extends Command
{
    protected $signature = 'communication:send-due';

    protected $description = 'Send every scheduled email that has fallen due';

    public function handle(EmailGateway $gateway): int
    {
        $result = Communication::dispatchDue($gateway);

        $this->info(sprintf(
            '%s of %s scheduled %s sent through the %s gateway; %s failed.',
            $result['sent'],
            $result['attempted'],
            $result['attempted'] === 1 ? 'message' : 'messages',
            $gateway->source(),
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
