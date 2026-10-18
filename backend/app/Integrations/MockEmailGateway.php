<?php

namespace App\Integrations;

use Carbon\CarbonImmutable;

/**
 * Mocked mailbox (ADR-0005, ADR-0017 §4). Deterministic, never random: it accepts every
 * message unless `CRM_EMAIL_SIMULATE_FAILURE=true`, which is how the failure and retry path
 * is demoed and tested without a mail provider.
 *
 * Every id is derived from the message reference, so the same message always gets the same
 * ids — that is what makes a repeated `communication:sync-inbound` idempotent instead of
 * inventing a second reply.
 */
final class MockEmailGateway implements EmailGateway
{
    public function send(OutboundEmail $email): EmailSendResult
    {
        if ((bool) config('crm.integrations.email.simulate_failure')) {
            return EmailSendResult::failed(
                'The mail provider rejected the message (mocked failure).',
            );
        }

        return EmailSendResult::sent(self::externalIdFor($email->reference));
    }

    public function received(array $sent): array
    {
        return array_map(
            fn (OutboundEmail $email): ReceivedEmail => new ReceivedEmail(
                externalId: self::replyExternalIdFor($email->reference),
                inReplyTo: self::externalIdFor($email->reference),
                fromName: $email->toName ?? $email->toAddress,
                fromAddress: $email->toAddress,
                toName: 'Primepower Sales',
                toAddress: null,
                subject: sprintf('Re: %s', $email->subject),
                body: sprintf(
                    "Thanks for your note on \"%s\" — we have it and will come back to you shortly.\n\n(This reply is part of the simulated mailbox; nothing left the machine.)",
                    $email->subject,
                ),
                receivedAt: CarbonImmutable::now(),
            ),
            $sent,
        );
    }

    public function source(): string
    {
        return 'Email (mocked)';
    }

    /**
     * The id this gateway assigns to a sent message. Exposed so the demo seed can write the
     * history a live send would have produced (spec 11 §Demo data) without repeating the
     * format in two places.
     */
    public static function externalIdFor(string $reference): string
    {
        return 'EMAIL-'.$reference;
    }

    /**
     * The id it assigns to the reply to that message — see `externalIdFor()`.
     */
    public static function replyExternalIdFor(string $reference): string
    {
        return 'EMAIL-RE-'.$reference;
    }
}
