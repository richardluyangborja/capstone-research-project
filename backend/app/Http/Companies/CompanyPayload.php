<?php

namespace App\Http\Companies;

use App\Models\Company;

/**
 * The company payload every account surface expects (spec 06 §API, spec 16 §Account 360):
 * the relations the page reads plus the roll-up counts. Kept in one place so the assignment,
 * activity and CRUD controllers always answer with the same shape.
 */
final class CompanyPayload
{
    public static function load(Company $company): Company
    {
        return $company->load(['owner', 'parent', 'primaryContact'])->loadCount([
            'sites',
            'children',
            'contacts',
            'opportunities',
            'contracts',
            'activities',
            'communications',
            'openOpportunities as open_opportunities_count',
        ]);
    }
}
