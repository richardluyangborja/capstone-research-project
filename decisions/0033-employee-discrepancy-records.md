# 0033 — Employee discrepancy records and the escalation workflow

- Status: Accepted
- Date: 2026-11-24

## Context

Module 09 T4 (`specs/21` §T4) lets a rep flag a disputed attendance, leave-balance, commission or
missing-deal figure so a manager can escalate it to Core 2 HR/Payroll. ADR-0032 §4 described that
flag as "an internal CRM `Activity` (`type: 'system'`) … without adding redundant tables".

That storage clause does not survive contact with the module 03/04 infrastructure it assumed:

1. **An `Activity` cannot hang off an employee.** `App\Models\Concerns\HasSubjectRecord::subjectTypes()`
   maps only `lead`, `company`, `contact`, `opportunity` and `contract`. Adding `user` would put
   non-record subjects into lead/account/deal timelines and into `Communication` visibility, whose
   gating lives inline in the module 03/04 controllers.
2. **`Activity` cannot hold the flag.** It has no category, reference, explanation or resolution
   columns, and `ActivityStatus` is the task lifecycle (`open`/`done`/`cancelled`), not a review
   lifecycle.
3. **Resolution needs an audit trail.** "Resolved by a manager" requires who/when/why, exactly the
   column set module 05 S4 added to `survey_responses` for the same reason.

The alternative — extending `SubjectRecord` and widening `activities` — buys no table at the cost of
changing shared visibility rules for two shipped modules. That trade is rejected.

## Decision

**A discrepancy is its own record, in its own table.**

1. `employee_discrepancies` holds one row per flag: `employee_id`, `raised_by`, `category`
   (`attendance`, `leave_balance`, `commission_calculation`, `missing_deal`), the rep's `explanation`
   (10–1000 characters), an optional CRM record reference (`subject_type`/`subject_id`, restricted to
   `opportunity`/`contract`), `status` (`open`/`resolved`), and the resolution block (`resolved_at`,
   `resolved_by`, `resolution_note`).
2. **A rep raises only their own flag** (`POST /employees/{public_id}/discrepancies`); a manager whose
   team the employee belongs to, or an admin, resolves it
   (`POST /employees/{public_id}/discrepancies/{discrepancy}/resolve`). The resolution note is
   optional; a second resolve is a 422 state error.
3. **"Under Review" is derived, not stored.** `status = open` renders as an amber pill in the profile;
   the label is the UI's word for the state.
4. **Notifications reach the team manager and every admin** (database channel, carrying a `url` to the
   employee profile), like every other bell item in this CRM.
5. **A `system` `Activity` is written only when a CRM record exists** — `missing_deal` on its
   opportunity, `commission_calculation` on its contract. `attendance` and `leave_balance` flags have
   no CRM subject, so they are recorded in their own row and reported as such; no timeline entry is
   fabricated.
6. **Core 2 stays untouched.** No gateway write path, no retry, no status mutation outside this table.

## Consequences

- ADR-0032 §4's *storage* clause is superseded by this decision; its workflow decision (self-service
  flag, manager escalation, read-only Core 2) stands unchanged.
- `HasSubjectRecord` and `activities` are unchanged, so modules 03 and 04 keep their current
  visibility semantics.
- Two enums (`DiscrepancyCategory`, `DiscrepancyStatus`), one table, one model, one controller and one
  notification describe the feature; T4 adds no field to `users`.
- The flag is CRM-internal history, so it is not deleted: a resolved row stays readable as the audit
  trail of what HR was asked to correct.
