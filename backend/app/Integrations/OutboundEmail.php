<?php

namespace App\Integrations;

use App\Models\Communication;

/**
 * The outbound part of the mocked email gateway (module 03 slice S2, ADR-0017 §4): the
 * content of one message, as the gateway needs it. Deliberately not the Eloquent model — a
 * driver should be replaceable without knowing our schema.
 */
final readonly class OutboundEmail
{
    public function __construct(
        public string $reference,
        public string $toAddress,
        public ?string $toName,
        public string $subject,
        public string $body,
    ) {}

    /**
     * The message as this record would send it, ready to hand to a gateway.
     */
    public static function forCommunication(Communication $communication): self
    {
        return new self(
            reference: $communication->reference,
            toAddress: (string) $communication->to_address,
            toName: $communication->to_name,
            subject: $communication->subject,
            body: (string) $communication->body,
        );
    }
}
