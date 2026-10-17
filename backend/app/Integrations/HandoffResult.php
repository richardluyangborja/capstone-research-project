<?php

namespace App\Integrations;

/**
 * Result of a handoff request to the Service department (spec 09 §2). Never an
 * exception: a failed handoff must not roll back the signed contract.
 */
final readonly class HandoffResult
{
    private function __construct(
        public bool $success,
        public ?string $reference,
        public ?string $message,
    ) {}

    public static function sent(string $reference): self
    {
        return new self(true, $reference, null);
    }

    public static function failed(string $message): self
    {
        return new self(false, null, $message);
    }
}
