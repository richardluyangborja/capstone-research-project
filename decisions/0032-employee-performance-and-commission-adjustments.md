# 0032 — Employee performance, commission, and quota tracking adjustments

- Status: Accepted
- Date: 2026-11-12

## Context

Module 09 (`specs/20-employee-performance-and-attendance-profiling.md`, ADR-0031) established the
read-only Core 2 integration boundary, 360° profiling, directory roster, and role-based compensation masking.
However, during baseline delivery several specific capabilities were deferred to avoid fabricating unverified
business rules:

1. **Commission Calculation**: S5 displayed closed-won counts and values, but deferred calculating earned
   commission amounts because no approved commission scheme or accelerator structure was defined.
2. **Quota Attainment & Quadrant Matrix**: S3, S5, and S6 rendered quota attainment as `—` and deferred
   populating the four operational quadrants on `/employees/leaderboard` because no per-user quota source existed.
3. **Leave Impact on Performance**: When Core 2 reported approved vacation or sick leave, raw working days were
   not adjusted, skewing attendance percentages and penalizing sales reps who took legitimate leave.
4. **Discrepancy Feedback Loop**: While Core 2 is strictly read-only, sales reps reviewing their attendance and
   commission had no structured in-app mechanism to flag synchronization errors or disputed biometric records.

## Decision

1. **Adopt a Tiered Accelerator Commission Model.**
   Commission is computed dynamically from period closed-won revenue against the rep's quota:
   - **Baseline Rate** (`crm.employees.commission_rate_baseline_percent`, default `2.0%`): Applied to revenue up to 100% of quota.
   - **Accelerator Rate** (`crm.employees.commission_rate_accelerator_percent`, default `3.5%`): Applied to incremental revenue exceeding 100% of quota.
   - If quota is undefined or zero, the baseline rate applies to total won revenue.

2. **Per-User Quota Assignment via User-Scoped Settings.**
   Rather than enforcing a uniform global quota, individual monthly and quarterly quotas can be assigned per sales rep:
   - Uses the existing Module 08 `Setting` infrastructure (`scope = 'user'`, `scope_id = user_id`, `key = 'crm.quota.monthly_php'`).
   - Managers (for their team members) and Admins can update individual quotas via `PATCH /api/v1/employees/{public_id}/quota`.
   - If no individual override exists, the system gracefully falls back to `crm.employees.quota_default_php`.

3. **Prorated Attendance & Adjusted Quota Display.**
   When Core 2 reports approved leave days (Vacation Leave, Sick Leave, Emergency Leave):
   - The attendance denominator subtracts approved leave days:
     $$\text{Working Days Net} = \text{Standard Business Days} - \text{Approved Leave Days}$$
     $$\text{Prorated Attendance \%} = \frac{\text{Days Present}}{\text{Working Days Net}} \times 100$$
   - An "Adjusted Quota" badge and calculation is displayed alongside the nominal quota to provide fair performance evaluation during extended leaves.

4. **In-CRM Discrepancy Flagging Workflow.**
   Sales reps may flag attendance, leave balance, or commission calculation discrepancies:
   - Exposed as `POST /api/v1/employees/{public_id}/discrepancies`.
   - Records an internal CRM `Activity` (`type: 'system'`) and dispatches a database notification to the user's direct Manager and Admin.
   - Generates an `under_review` status pill on the affected record row in the profile.
   - **Strict Zero-Mutation Boundary**: The CRM will **never** mutate Core 2 database records; the flag serves as an internal escalation and audit trail for managers to liaise with HR/Payroll.

5. **Unblock Leaderboard Quadrants & Workforce AI Report.**
   - Enables the 4-quadrant scatter matrix on `/employees/leaderboard` using live quota attainment (Y-axis) and prorated attendance (X-axis).
   - Authorizes delivery of Module 07's 4th AI report variant (`"workforce"`).

## Consequences

- Resolves all deferred items from Module 09 S3, S5, S6, and S7 without violating Core 2 read-only boundaries.
- Uses existing Module 08 typed `Setting` and `Activity` infrastructure without adding redundant tables.
- Provides equitable performance benchmarking for sales reps taking authorized leaves.
- Formalized into delivery slices T1–T5 in [`specs/21-module-09-performance-and-commission-adjustments.md`](../specs/21-module-09-performance-and-commission-adjustments.md).
