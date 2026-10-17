<?php

namespace App\Events;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A qualified lead became durable records — company + contact(s) + opportunity (spec 08).
 * Enables the opportunity/contract flow (submodule 09) and the service handoff.
 */
class LeadConverted
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int, Contact>  $contacts
     */
    public function __construct(
        public Lead $lead,
        public Company $company,
        public Opportunity $opportunity,
        public array $contacts = [],
        public ?User $actor = null,
    ) {}
}
