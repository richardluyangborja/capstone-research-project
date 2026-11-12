# Module 09 — Employee Performance & Attendance Profiling

- Status: **S0 accepted; S1–S7 proposed**
- Depends on: ADR-0004, ADR-0005, ADR-0007, ADR-0016, ADR-0020, ADR-0021, ADR-0025, ADR-0030, ADR-0031, `00-data-model.md`, `specs/14`, `specs/15`, `specs/19`, `implementation/system-roles.md`, `implementation/list-of-departments.md`

## Purpose

This module establishes a unified **Employee Profiling and Workforce Performance** system inside the CRM
for Sales Representatives and Sales Managers. It correlates CRM commercial output (leads generated,
pipeline velocity, deals won, revenue quota attainment, client satisfaction) with external operational
discipline and compensation figures (attendance rate, tardiness, leave balances, base salary, and sales commissions).

### The Primary Boundary Constraint: Pure Read-Only Projection
Per `implementation/list-of-departments.md` and ADR-0031, **Core Transaction 2: Human Resource Information and Operations**
is the sole authoritative master for employee records, timekeeping, attendance, leave management, payroll,
and formal performance appraisals.

The CRM will **never** create, update, onboard, suspend, or terminate employee records, nor approve leaves,
edit timesheet punches, or disburse payroll. Those workflows belong strictly to Core 2. The CRM acts purely
as an **analytical consumer and profiler**.

---

## Architecture & Integration Seam

Following ADR-0005, ADR-0020, and ADR-0025, all external Core 2 employee data is consumed via a dedicated
gateway interface in `backend/app/Integrations/`:

```php
namespace App\Integrations;

interface Core2EmployeeGateway
{
    /** Retrieve Core 2 master employee record (job title, department, rank, tenure) */
    public function profile(int $userId): ?Core2EmployeeProfile;

    /** Retrieve attendance, tardiness, and leave balances for an inclusive date window */
    public function attendance(int $userId, string $from, string $to): ?Core2AttendanceSummary;

    /** Retrieve salary structure, allowances, and payslip history */
    public function compensation(int $userId): ?Core2CompensationProfile;

    /** Retrieve historical formal performance evaluations from HR */
    public function appraisals(int $userId): ?array;
}
```

### Data Transfer Objects (DTOs)
All DTOs are `final readonly` and assemble external data without database persistence:

1. **`Core2EmployeeProfile`**:
   - `employeeReference`: string (e.g. `EMP-2026-0042`)
   - `officialTitle`: string (e.g. "Senior Account Executive")
   - `department`: string (e.g. "Business Development & Sales")
   - `employmentStatus`: string (`regular`, `probationary`)
   - `tenureMonths`: int
   - `shiftSchedule`: string (e.g. "08:00 - 17:00 PHT, Mon-Fri")
   - `directManager`: ?string

2. **`Core2AttendanceSummary`**:
   - `userId`: int
   - `attendanceRate`: float (0–100%)
   - `daysPresent`: int
   - `lateArrivalsCount`: int
   - `lateArrivalsMinutes`: int
   - `undertimeHours`: float
   - `absencesCount`: int
   - `leaveBalances`: array{vacation_leave_remaining: float, sick_leave_remaining: float, emergency_leave_remaining: float}

3. **`Core2CompensationProfile`**:
   - `userId`: int
   - `baseSalary`: ?float (in PHP ₱; null if masked)
   - `fixedAllowances`: ?float (in PHP ₱; null if masked)
   - `isMasked`: bool
   - `recentPayslips`: array<array{period: string, gross_pay: ?float, net_pay: ?float, status: string}>

4. **`Core2AppraisalSummary`**:
   - `appraisalId`: string (e.g. `APR-2026-Q3`)
   - `periodLabel`: string (e.g. "2026 Q3 Performance Appraisal")
   - `overallRating`: float (1.0 to 5.0 scale)
   - `competencies`: array<string, float> (e.g. `['client_negotiation' => 4.5, 'attendance_punctuality' => 4.0]`)
   - `evaluatorNotes`: string
   - `conductedAt`: string (ISO-8601)

### Provenance and Resilience
- Every response containing Core 2 information carries provenance:
  `meta.source = "Core 2 HR, Attendance & Payroll (read-only)"`, `meta.read_only: true`, `meta.as_of = ISO-8601`.
- If `config('crm.integrations.core2_employees.simulate_failure')` is true or the Core 2 service is unreachable,
  the gateway returns `null`. The UI displays `—` (em-dash) and an informational banner (*"Core 2 HR Service Unavailable"*).
  The CRM does not throw a 500 error or display false zero values.

---

## Role Scoping & Confidentiality Matrix

Compensation and salary data are strictly governed by server-side policy gating:

| Data Surface | `salesrep` | `manager` | `admin` | `superadmin` |
| :--- | :--- | :--- | :--- | :--- |
| **Directory Roster (`/employees`)** | Team peers (basic info + CRM scores) | Full assigned team | All employees | All employees |
| **CRM Commercial Output** | Own detailed metrics | Team members' metrics | Org-wide metrics | Org-wide metrics |
| **Attendance & Leave Figures** | Own attendance & leaves | Team members' attendance | Org-wide attendance | Org-wide attendance |
| **Base Salary & Allowances** | **Own only** | **Own only** (Team members masked `₱ ••••••`) | **Full view** | **Full view** |
| **Sales Commission Breakdown** | Own calculated commissions | Own + Team members' earned commissions | Full view | Full view |
| **HR Appraisals & Notes** | Own appraisal & feedback | Team appraisals | Full view | Full view |

When unauthorized roles request `/employees/{id}/compensation`, the server returns `403 Forbidden` if attempting
to access another user's compensation. When a manager requests a subordinate's compensation, `is_masked: true`
and `base_salary: null` are returned, while deal-derived sales commissions remain visible.

---

## Cross-Cutting Module Adjustments

### 1. Administration & Platform Operations (Module 08, Spec 19)
The new module requires bounded additions to Module 08:
- **Settings Allow-List Expansion (`specs/19 §Settings allow-list`):**
  - `crm.employees.quota_default_php` (decimal: default monthly sales quota per salesrep, e.g. `₱500,000.00`)
  - `crm.employees.commission_rate_percent` (decimal: default commission rate on won deals, e.g. `2.5%`)
  - `crm.employees.attendance_benchmark_percent` (float: benchmark attendance threshold, e.g. `95.0%`)
  - `crm.employees.salary_mask_for_managers` (bool: whether managers can view subordinate base salary)
  - `crm.integrations.core2_employees.simulate_failure` (bool: failure simulation toggle)
- **User Administration (`specs/19 S2`):**
  - Exposes `core2_employee_id` (e.g. `EMP-2026-0042`) on the User Resource in `/admin/users`.
  - Displays Core 2 identity link status badge.
- **Operational Health Checks (`specs/19 S6`):**
  - Adds `core2_employees` check to `GET /admin/operations/health`.
- **Audit Event Vocabulary (`specs/19 S5`):**
  - Adds `employee.compensation_viewed` (severity `notice`) and `employee.quota_updated` (severity `info`).
- **Safe Maintenance Actions (`specs/19 S7`):**
  - Adds `Refresh Core 2 Employee Projection Cache` to flush cached employee projections.

### 2. Dashboard & Analytics (Module 06, Spec 14)
- Deep-links staff scorecard rows from `/dashboard/staff-performance` directly to `/employees/{public_id}`.
- Adds Quota Attainment Velocity metric (`won_value / monthly_quota`).
- Adds Team Discipline & Reliability Index:
  $$\text{Reliability Index} = (\text{Core 2 Attendance \%} \times 0.6) + (\text{CRM Follow-up On-Time \%} \times 0.4)$$
- Adds a Workforce Alert Card to the manager's operational strip for reps showing attendance risks.

### 3. AI Management Reports (Module 07, Spec 15 & `ai/`)
- Introduces the 4th Report Variant: `"workforce"` (*Workforce Performance & Productivity*).
- `ai/app/schemas.py`: `Variant = Literal["executive", "pipeline", "client_health", "workforce"]`.
- `ai/app/reports.py`:
  - `VARIANT_GUIDANCE["workforce"]`: Evaluation of quota delivery, attendance reliability, and sales cycle velocity.
  - `ALLOWED_INSIGHTS["workforce"] = ("quota", "attendance", "velocity", "coaching_risk")`.
  - `ALLOWED_ACTIONS["workforce"] = ("open_leaderboard", "open_employees", "open_worklist", "open_board")`.
- Privacy Guardrail: Context sent from Laravel to `ai/` is strictly aggregated and PII-free (no employee names, IDs, or individual salary figures).

---

## REST API Surface (`/api/v1`)

| Endpoint | Method | Role Policy | Purpose |
| :--- | :--- | :--- | :--- |
| `/api/v1/employees` | `GET` | Authenticated | Paginated employee directory with blended CRM KPIs and attendance rates |
| `/api/v1/employees/{public_id}` | `GET` | Policy-scoped | 360° summary profile: Core 2 metadata + CRM commercial summary |
| `/api/v1/employees/{public_id}/performance` | `GET` | Policy-scoped | Granular CRM performance metrics: won deals, pipeline, CSAT, follow-up adherence |
| `/api/v1/employees/{public_id}/attendance` | `GET` | Policy-scoped | Core 2 timekeeping feed: attendance %, tardiness count, minutes late, leave balances |
| `/api/v1/employees/{public_id}/compensation` | `GET` | Own or Admin | Base salary, allowances, earned CRM commissions (masked for unauthorized managers) |
| `/api/v1/employees/{public_id}/appraisals` | `GET` | Policy-scoped | Core 2 HR quarterly appraisals and competency ratings |
| `/api/v1/employees/leaderboard` | `GET` | Manager+ | Team ranking across quota attainment, revenue closed, and attendance punctuality |

---

## Delivery Slices (ADR-0016 Cadence)

| Slice | Branch | Focus | Key Deliverable | Status |
| :--- | :--- | :--- | :--- | :--- |
| **S0** | `feature/module-09-employees-s0` | Architecture, Spec 20 & ADR-0031 | Formal contract, data boundaries, and threat model | **Accepted** |
| **S1** | `feature/module-09-core2-gateway-s1` | Core 2 Integration Gateway & DTOs | Gateway interface, DTOs, deterministic mock, resilience | Proposed |
| **S2** | `feature/module-09-backend-api-s2` | Backend API Routes & Policy Scoping | `/api/v1/employees`, role masking, CRM KPI aggregation | Proposed |
| **S3** | `feature/module-09-frontend-directory-s3` | Employee Directory Roster UI | `/employees` table, role filters, quota badges | Proposed |
| **S4** | `feature/module-09-profile-360-s4` | Employee 360° Profile & Core Tabs | `/employees/:id` with Overview, CRM, Attendance & Appraisals | Proposed |
| **S5** | `feature/module-09-compensation-s5` | Compensation Profiling & Masking | Base salary, CRM commissions, payslips, manager mask | Proposed |
| **S6** | `feature/module-09-leaderboard-s6` | Discipline vs. Output Correlation | `/employees/leaderboard` matrix & ranking view | Proposed |
| **S7** | `feature/module-09-cross-cutting-s7` | Admin, Analytics, AI & Verification | Module 06/07/08 updates, deterministic seeds, test suites | Proposed |

---

## Slice Definitions & Acceptance Criteria

### S0 — Employee Profiling Contract & Threat Model *(Accepted)*
Ratify the formal specification and ADR-0031. Lock down the read-only projection boundary, role-based
masking matrix, and cross-cutting integration contracts for Modules 06, 07, and 08.

Acceptance:
- [x] ADR-0031 recorded in `decisions/`.
- [x] Spec 20 recorded in `specs/`.
- [x] Zero CRM database mutations for Core 2 HR records confirmed.
- [x] Role-based salary masking policy specified.

### S1 — Core 2 Integration Gateway & Deterministic DTOs *(Proposed)*
Deliver `Core2EmployeeGateway`, `MockCore2EmployeeGateway`, and DTOs in `backend/app/Integrations/`.
Values are deterministically derived from `User.id` (crc32 seed, no Faker). Failure simulation switch
supported.

Acceptance:
- [ ] DTOs `Core2EmployeeProfile`, `Core2AttendanceSummary`, `Core2CompensationProfile`, `Core2AppraisalSummary` implemented.
- [ ] Deterministic fixture output for demo users (Sam, Maria, Liza).
- [ ] `CRM_CORE2_SIMULATE_FAILURE=true` safely returns `null` without throwing exceptions.
- [ ] Unit tests verify gateway resilience.

### S2 — Backend API Routes & Policy Scoping *(Proposed)*
Deliver `EmployeeController`, `EmployeePolicy`, and API resources under `/api/v1/employees`.
Blend CRM queries with Core 2 DTOs. Enforce strict role-based policy masking.

Acceptance:
- [ ] Salesrep accessing another user's `/compensation` receives `403 Forbidden`.
- [ ] Manager accessing a team member's `/compensation` receives masked base salary (`is_masked: true`).
- [ ] Admin / Superadmin receives full unmasked figures.
- [ ] Feature tests assert policy boundaries and query performance.

### S3 — Frontend Employee Directory Roster UI *(Proposed)*
Deliver `/employees` page in frontend with searchable, filterable table. Displays Name, Role, Team,
Won Revenue (₱), Quota Attainment (%), and Attendance Rate (%).

Acceptance:
- [ ] Top-level "Employees" navigation added to sidebar for authorized roles.
- [ ] Period filter (`30d`, `Quarter`, `YTD`) updates figures smoothly.
- [ ] Row click navigates to `/employees/{public_id}`.

### S4 — Frontend Employee 360° Profile & Core Tabs *(Proposed)*
Deliver `/employees/{public_id}` with header card (Core 2 metadata, tenure, shift) and tabs for
Overview, CRM Performance, Core 2 Attendance, and HR Appraisals.

Acceptance:
- [ ] Core 2 sections prominently display the `core2 · HR (read-only)` badge.
- [ ] Timekeeping tab renders late arrivals, undertime, and leave balance meters.
- [ ] Appraisals tab renders formal competency evaluation scores.
- [ ] Graceful fallback (`—`) renders when Core 2 feed is unavailable.

### S5 — Compensation Profiling & Masking *(Proposed)*
Deliver the Compensation & Salary tab on `/employees/{public_id}`.
Renders Base Salary (₱), Fixed Allowances, CRM Closed-Won Deal Commissions, and Payslip summary.

Acceptance:
- [ ] Sales reps can view only their own compensation.
- [ ] Subordinate base salary is masked (`₱ ••••••`) for managers.
- [ ] Commissions earned from CRM deals match actual closed-won opportunities.
- [ ] Single currency PHP formatting (`₱1,234.56`) strictly applied.

### S6 — Discipline vs. Output Correlation & Leaderboard *(Proposed)*
Deliver `/employees/leaderboard` featuring the "Discipline vs. Output" matrix.
Plots Attendance Rate (%) against Quota Attainment (%) across four operational quadrants.

Acceptance:
- [ ] Scatter/quadrant visual categorizes staff into Top Performers, Coaching Candidates, Burnout Risks, and Critical PIP.
- [ ] Interactive tooltips show rep revenue and attendance stats.
- [ ] Team ranking table supports sorting by revenue, quota %, and punctuality.

### S7 — Cross-Cutting Extensions, Deterministic Seeds & Verification *(Proposed)*
Deliver companion updates to Modules 06, 07, and 08. Extend database seeders with deterministic
Core 2 employee mappings. Run full backend and frontend verification suites.

Acceptance:
- [ ] Module 08: settings allow-list updated, `core2_employees` health probe active, audit event logged.
- [ ] Module 06: staff scorecard rows link to `/employees/{id}`, reliability index exposed.
- [ ] Module 07: `"workforce"` report variant generated by FastAPI service without PII.
- [ ] Backend tests pass (`php artisan test`).
- [ ] Frontend tests and build pass (`npm run test && npm run build`).

---

## Deferred Capabilities

The following capabilities are deliberately deferred:
- Creating, editing, or deleting employee records inside CRM (Core 2 owns).
- Clocking in/out or adjusting biometric punch records (Core 2 Timekeeping owns).
- Submitting or approving leave requests (Core 2 Leave Management owns).
- Executing payroll payouts or tax deductions (Core 2 Payroll owns).
- Multi-currency salary support (fixed single-currency PHP by product rule).
- Dynamic commission scheme rule-builder UI.
