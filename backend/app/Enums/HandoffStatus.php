<?php

namespace App\Enums;

/**
 * Outcome of the last request to the (mocked) Service department for a signed
 * engagement (spec 09 §2). A `failed` handoff keeps the client in `onboarding` and
 * exposes a retry — it never rolls back the contract.
 */
enum HandoffStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
}
