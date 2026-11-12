# 21 — Module 09 Performance & Commission Adjustments

- Status: **Proposed (T1–T5)**
- Depends on: ADR-0004, ADR-0005, ADR-0007, ADR-0016, ADR-0020, ADR-0021, ADR-0025, ADR-0030, ADR-0031, ADR-0032, `specs/14`, `specs/15`, `specs/19`, `specs/20-employee-performance-and-attendance-profiling.md`

## Purpose

This specification defines the post-baseline tracking adjustments for **Module 09 (Employee Performance & Attendance Profiling)**.
It directly addresses the capabilities deferred in `specs/20` during slices S3, S5, S6, and S7:

1. **Per-User Quota Targets**: Replaces the uniform fallback quota with customizable monthly and quarterly targets per sales rep.
2. **Tiered Accelerator Commissions**: Implements a two-tier commission calculation (baseline rate up to 100% quota, accelerator rate on excess revenue).
3. **Leave-Prorated Attendance & Benchmarks**: Dynamically adjusts working-day denominators and quota expectations when Core 2 reports approved leave days.
4. **In-CRM Discrepancy Escalation**: Enables sales reps to flag disputed attendance records or missing deal commissions directly to their Manager without mutating Core 2.
5. **Leaderboard Quadrant Enablement & Workforce AI Variant**: Fully activates the four operational quadrants on `/employees/leaderboard` and introduces the `"workforce"` AI management report variant.

---

## Decisions in Force

| ADR | Decision |
| :--- | :--- |
| **0031** | Core 2 HR, attendance, payroll, and appraisals are strictly read-only projections; no CRM employee CRUD or timesheet mutation. |
| **0032** | Tiered accelerator commission model, per-user quota overrides via user-scoped `Setting`, leave-prorated attendance, and in-CRM discrepancy flags. |

---

## Architecture & Data Contracts

### 1. Quota Target Contract
Per-user quotas are persisted using Module 08's existing `Setting` infrastructure (`specs/19`):
- `scope = 'user'`
- `scope_id = {user_id}`
- `key = 'crm.quota.monthly_php'` (and `crm.quota.quarterly_php`)
- `value_type = 'decimal'`

If a user lacks an explicit setting, the system defaults to `crm.employees.quota_default_php` (from system settings).

```jsonc
// PATCH /api/v1/employees/{public_id}/quota
{
  "monthly_quota": 750000.00,
  "quarterly_quota": 2250000.00,
  "notes": "Q4 Target adjusted for senior account expansion"
}
```

### 2. Tiered Commission Formula
Commission calculation uses two configurable system settings:
- `crm.employees.commission_rate_baseline_percent` (default `2.0%`)
- `crm.employees.commission_rate_accelerator_percent` (default `3.5%`)

Let $R$ be the closed-won revenue in the period, and $Q$ be the active quota for the period:
- **Case 1: $R \le Q$ (Below or at Quota)**:
  $$\text{Earned Commission} = R \times \frac{\text{Baseline Rate}}{100}$$
- **Case 2: $R > Q$ (Exceeding Quota)**:
  $$\text{Earned Commission} = \left( Q \times \frac{\text{Baseline Rate}}{100} \right) + \left( (R - Q) \times \frac{\text{Accelerator Rate}}{100} \right)$$

The compensation payload exposes the transparent breakdown:
```jsonc
{
  "commission": {
    "won_value": 1250000.00,
    "quota_value": 1000000.00,
    "attainment_percent": 125.0,
    "baseline_rate_percent": 2.0,
    "accelerator_rate_percent": 3.5,
    "baseline_commission": 20000.00,
    "accelerator_commission": 8750.00,
    "total_earned_commission": 28750.00,
    "tier_applied": "accelerator"
  }
}
```

### 3. Leave Proration Formula
Let $D_{\text{total}}$ be standard business days in the period (excluding weekends/public holidays), $D_{\text{leave}}$ be Core 2 approved leave days, and $D_{\text{present}}$ be days present:
$$\text{Working Days Net} = \max(1, D_{\text{total}} - D_{\text{leave}})$$
$$\text{Prorated Attendance \%} = \min\left(100.0, \frac{D_{\text{present}}}{\text{Working Days Net}} \times 100\right)$$
$$\text{Prorated Quota} = Q \times \frac{\text{Working Days Net}}{D_{\text{total}}}$$

### 4. Discrepancy Flagging Contract
When a rep flags an item, the CRM creates an internal activity and database notification:
```jsonc
// POST /api/v1/employees/{public_id}/discrepancies
{
  "category": "attendance|leave_balance|commission_calculation|missing_deal",
  "record_reference": "2026-10-24 Biometric Punch",
  "disputed_value": "Marked Absent",
  "claimed_value": "Present (Manual HR slip #1049 approved)",
  "notes": "Scanner malfunction at client site; slip signed by supervisor."
}
```

---

## Delivery Slices (T1–T5 Cadence)

| Slice | Branch | Focus | Key Deliverable | Status |
| :--- | :--- | :--- | :--- | :--- |
| **T1** | `feature/module-09-adjustments-quotas-t1` | Per-User Quota Management | `PATCH /employees/{id}/quota`, user-setting resolution, directory/profile integration | Proposed |
| **T2** | `feature/module-09-adjustments-commission-t2` | Tiered Accelerator Commission | Configurable baseline + accelerator rates, transparent breakdown in Compensation tab | Proposed |
| **T3** | `feature/module-09-adjustments-leave-proration-t3` | Leave Proration & Adjusted Targets | Dynamic net working days, prorated attendance %, adjusted quota badge | Proposed |
| **T4** | `feature/module-09-adjustments-discrepancies-t4` | In-CRM Discrepancy Escalation | `POST /discrepancies`, manager notifications, profile review status badges | Proposed |
| **T5** | `feature/module-09-adjustments-quadrants-ai-t5` | Leaderboard Quadrants & AI Workforce | Activate 4-quadrant scatter matrix on Leaderboard, FastAPI `"workforce"` report variant | Proposed |

---

## Slice Definitions & Acceptance Criteria

### T1 — Per-User Quota Management & Assignment *(Proposed)*
Deliver the backend storage and API endpoints to manage per-user sales quotas, integrating the quota
values into the Directory and Profile views.

Acceptance:
- [ ] User-scoped settings `crm.quota.monthly_php` and `crm.quota.quarterly_php` persist via `PATCH /api/v1/employees/{public_id}/quota`.
- [ ] Policy gating: Admins can update any quota; Managers can update quotas for direct team members only; Salesreps are blocked with `403 Forbidden`.
- [ ] System falls back to `crm.employees.quota_default_php` when no individual override is stored.
- [ ] Quota Attainment (%) renders accurately on `/employees` (Directory Roster) and `/employees/{public_id}` (Profile Overview).
- [ ] Audit log records `employee.quota_updated` on change.

### T2 — Tiered Accelerator Commission Engine *(Proposed)*
Deliver the dynamic tiered commission calculation engine in `EmployeeService` and expose the calculation
breakdown in the Compensation tab.

Acceptance:
- [ ] Adds `crm.employees.commission_rate_baseline_percent` and `crm.employees.commission_rate_accelerator_percent` to Module 08 settings allow-list.
- [ ] Dynamic calculation applies baseline rate up to 100% quota and accelerator rate to excess revenue.
- [ ] `/employees/{public_id}/compensation` returns the detailed `commission` object (won value, quota, attainment %, baseline commission, accelerator commission, total earned).
- [ ] Compensation tab UI displays the transparent tiered breakdown card with an "Accelerator Active" badge when quota is exceeded.
- [ ] If viewing a subordinate as manager, commission details remain visible while base salary remains masked.

### T3 — Leave Proration & Adjusted Targets *(Proposed)*
Deliver working-day and quota proration based on Core 2 approved leave records (Vacation Leave, Sick Leave, Emergency Leave).

Acceptance:
- [ ] Net working days correctly subtracts Core 2 approved leave days from the period's business day count.
- [ ] Attendance rate calculation uses net working days as denominator, preventing unfair tardiness penalization for approved absences.
- [ ] Quota badge displays "Adjusted Quota" with an informational popover explaining the leave-prorated calculation.
- [ ] Attendance tab indicates the number of approved leave days counted in the proration.

### T4 — In-CRM Discrepancy Escalation Workflow *(Proposed)*
Deliver the discrepancy flagging mechanism for attendance and commission records.

Acceptance:
- [ ] `POST /api/v1/employees/{public_id}/discrepancies` allows authenticated users to submit discrepancy reports on their own records.
- [ ] Validates category (`attendance`, `leave_balance`, `commission_calculation`, `missing_deal`) and explanation length (10–1000 characters).
- [ ] Dispatches a database notification to the salesrep's manager and logs an internal system `Activity`.
- [ ] The affected record row displays an amber `Under Review` tag in the UI until resolved by a Manager/Admin.
- [ ] Zero write operations executed on Core 2 (pure CRM internal workflow).

### T5 — Full Leaderboard Quadrants & Workforce AI Report *(Proposed)*
Deliver the four operational quadrants on `/employees/leaderboard` and the `"workforce"` AI management report variant.

Acceptance:
- [ ] `/employees/leaderboard` quadrant panels populate staff into *Top Performers*, *Coaching Candidates*, *Burnout Risks*, and *Critical PIP* based on live quota attainment and prorated attendance.
- [ ] Interactive quadrant cards show hover tooltips with rep revenue, quota %, and attendance stats.
- [ ] Team ranking table supports sorting by Quota Attainment (%).
- [ ] FastAPI service (`ai/`) implements the `"workforce"` report variant with guidance, insights (`quota`, `attendance`, `velocity`, `coaching_risk`), and actions.
- [ ] Laravel context builder dispatches aggregated, PII-free workforce metrics to `ai/`.

---

## Verification & Test Plan

1. **Backend Unit & Feature Tests (`backend/tests/Feature/EmployeeAdjustmentsTest.php`)**:
   - Quota override resolution (user override vs team vs global default).
   - Tiered commission math validation (below quota, exact quota, above quota accelerator).
   - Leave proration edge cases (zero leave days, partial days, leave exceeding working days).
   - Discrepancy submission validation and notification delivery.
   - Leaderboard quadrant assignment logic.

2. **Frontend Component & Route Tests (`frontend/src/pages/`)**:
   - Quota attainment percentage formatting on Directory and Profile.
   - Compensation tab tiered breakdown rendering.
   - Discrepancy modal form submission and `Under Review` badge display.
   - Leaderboard quadrant visualization and sorting.

3. **Code Style & Static Analysis**:
   - `cd backend && vendor/bin/pint --dirty`
   - `cd frontend && npm run lint && npm run test -- --run && npm run build`
