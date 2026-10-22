<?php

namespace App\Events;

use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody closed the loop on an unhappy answer (module 05 slice S4, spec 13 §Events): the account
 * owner or a manager wrote down what was done. The listener mirrors the note onto the account
 * timeline — the response keeps the resolution itself.
 */
class SurveyResponseResolved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public SurveyResponse $response,
        public User $resolvedBy,
    ) {}
}
