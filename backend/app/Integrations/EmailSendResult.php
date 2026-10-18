<?php

namespace App\Integrations;

/**
 * What the gateway said about an outbound message (spec 11 §S2). Never an exception: a
 * rejected email is history the rep has to be able to read and retry, not a crash.
 */
final readonly class EmailSendResult
{
    private function __construct(
        public bool $success,
        public ?string $externalId,
        public ?string $message,
    ) {}

    public static function sent(string $externalId, ?string $message = null): self
    {
        return new self(true, $externalId, $message);
    }

    public static function failed(string $message): self
    {
        return new self(false, null, $message);
    }
}
