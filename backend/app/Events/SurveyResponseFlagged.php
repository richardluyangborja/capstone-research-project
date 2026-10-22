<?php

namespace App\Events;

use App\Models\SurveyResponse;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A client's answer crossed the unhappy line (module 05 slice S4, spec 13 §Events). The listener
 * makes it somebody's job: one bell notification for the account owner and one module-04 task on
 * the account, so the call back is in the worklist instead of only in a report.
 */
class SurveyResponseFlagged
{
    use Dispatchable, SerializesModels;

    public function __construct(public SurveyResponse $response) {}
}
