<?php

namespace App\Events;

use App\Models\SurveyResponse;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A client answered a survey (module 05 slice S2, spec 13 §Events). The listener mirrors it
 * onto the account timeline; slice S4 hangs the closed loop (the call-back task and the
 * notification) off the same fact.
 */
class SurveyResponseSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public SurveyResponse $response) {}
}
