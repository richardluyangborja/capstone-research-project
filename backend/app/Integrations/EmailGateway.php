<?php

namespace App\Integrations;

/**
 * Email transport (module 03 slice S2, ADR-0017 §4). Email is a mocked integration like the
 * Service handoff (ADR-0005): the CRM has no mail infrastructure, so a driver either fakes
 * the send or talks to a real provider — callers never change.
 *
 * `source()` is what the API reports as `meta.source`, so a mocked send is never mistaken
 * for a real one (spec 11 §Payloads).
 */
interface EmailGateway
{
    /**
     * Hand one message to the gateway. Never throws for a delivery failure — the result
     * carries it, because a bounced email is history (spec 11 §S2).
     */
    public function send(OutboundEmail $email): EmailSendResult;

    /**
     * The messages this mailbox has received, for the given messages we sent: inbound is
     * **pulled**, never pushed (spec 11 §Business rules 7). A driver returns [] until it has
     * something new; the caller de-duplicates on `ReceivedEmail::$externalId`.
     *
     * @param  array<int, OutboundEmail>  $sent
     * @return array<int, ReceivedEmail>
     */
    public function received(array $sent): array;

    /**
     * How the payload should describe this source, e.g. "Email (mocked)".
     */
    public function source(): string;
}
