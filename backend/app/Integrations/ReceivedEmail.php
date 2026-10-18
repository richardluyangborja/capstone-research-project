<?php

namespace App\Integrations;

use Carbon\CarbonImmutable;

/**
 * A message the mocked mailbox has "received" (spec 11 §S2, §Business rules 7). Inbound is
 * pulled, never pushed: the sync command asks for these and records them.
 *
 * `inReplyTo` names the outbound message this answers, when it answers one — that is how a
 * reply finds the record it belongs to, so no message is ever orphaned.
 */
final readonly class ReceivedEmail
{
    public function __construct(
        public string $externalId,
        public ?string $inReplyTo,
        public string $fromName,
        public string $fromAddress,
        public string $toName,
        public ?string $toAddress,
        public string $subject,
        public string $body,
        public CarbonImmutable $receivedAt,
    ) {}
}
