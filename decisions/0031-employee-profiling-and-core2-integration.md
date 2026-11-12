# 0031 — Employee profiling and Core 2 HR integration boundary

- Status: Accepted
- Date: 2026-11-12

## Context

CRM operators (sales representatives, sales managers, administrators) track commercial performance
(leads, opportunities, pipeline value, communication cadence, and client satisfaction) within the
CRM. However, workforce discipline, operational punctuality, compensation structures, and formal
appraisals are owned outside the CRM by **Core Transaction 2: Human Resource Information and
Operations** (per `implementation/list-of-departments.md`).

Management requires visibility into sales reps' and managers' performance, attendance, and salary
info to evaluate whether commercial pipeline generation correlates with operational discipline and
fair compensation.

Allowing the CRM to perform employee CRUD, timesheet approvals, biometric logging, leave approvals,
or payroll calculations would violate repository boundaries and recreate external HR subsystems
(ADR-0005, ADR-0025). Furthermore, compensation details (base salary, fixed allowances, and commissions)
are sensitive personal data requiring strict role-based data protection and auditability.

## Decision

1. **Module 09 is "Employee Performance & Attendance Profiling" (`specs/20`).**
   The module provides workforce profiling and performance tracking inside the CRM without performing
   employee management.

2. **Core 2 integration is strictly a read-only projection.**
   The CRM will not create, edit, onboard, suspend, or terminate employee records, nor approve leaves,
   edit timesheets, or disburse payroll. Core 2 remains the sole authoritative system of record.
   The integration follows ADR-0005 and ADR-0025: a `Core2EmployeeGateway` interface, `MockCore2EmployeeGateway`
   driver, and `final readonly` DTOs (`Core2EmployeeProfile`, `Core2AttendanceSummary`,
   `Core2CompensationProfile`, and `Core2AppraisalSummary`) in `backend/app/Integrations/`.

3. **No new persistent database tables for external HR records.**
   Core 2 profile, attendance, compensation, and appraisal data are assembled dynamically on read.
   CRM `User` models optionally link to an external `core2_employee_id` (e.g. `EMP-2026-0042`).

4. **Provenance and resilience.**
   Every response carrying external HR data returns provenance metadata: `meta.source = "Core 2 HR,
   Attendance & Payroll (read-only)"`, `meta.read_only: true`, and `meta.as_of`. When Core 2 is unavailable
   or simulated failure is active (`crm.integrations.core2_employees.simulate_failure=true`), the CRM
   degrades gracefully by showing `—` (em-dash) and an advisory alert, never failing the page or returning `0`.

5. **Confidentiality and role-based data masking.**
   Compensation and salary data are protected by strict server-side policy gating:
   - `salesrep`: May view only their own compensation and commission breakdown. Peer or manager compensation
     returns `403 Forbidden`.
   - `manager`: May view team members' CRM performance, Core 2 attendance, and earned deal commissions.
     Base salary is masked by default (`₱ ••••••`) unless organization policy permits.
   - `admin` / `superadmin`: May view authorized org-wide compensation and attendance profiling.

6. **Cross-cutting module extensions:**
   - **Module 08 (Administration & Platform Operations, spec `19`):**
     - Settings allow-list adds `crm.employees.quota_default_php`, `crm.employees.commission_rate_percent`,
       `crm.employees.attendance_benchmark_percent`, `crm.employees.salary_mask_for_managers`, and
       `crm.integrations.core2_employees.simulate_failure`.
     - Health checks add the `core2_employees` gateway probe.
     - Audit vocabulary adds `employee.compensation_viewed` (severity `notice`) and `employee.quota_updated`.
     - User administration in `/admin/users` exposes Core 2 employee identity mapping.
     - Safe maintenance actions add `Refresh Core 2 Employee Projection Cache`.
   - **Module 06 (Dashboard & Analytics, spec `14`):**
     - Staff scorecard rows link directly to `/employees/{public_id}`.
     - Introduces Quota Attainment Velocity and the Team Discipline & Reliability Index.
   - **Module 07 (AI Management Reports, spec `15`):**
     - Adds a 4th report variant: `"workforce"` (*Workforce Performance & Productivity*).
     - Context sent to `ai/` is aggregated and PII-free (no employee names, IDs, or individual salaries).

## Consequences

- The CRM gains comprehensive workforce profiling and correlation between commercial output and
  workplace discipline.
- Core 2 department boundaries remain intact: zero write operations, no duplicated HR tables.
- Sensitive compensation data is protected by strict role masking and immutable security audit logs.
- The delivery cadence follows S0–S7 slices per ADR-0016.
- The detailed specification is documented in [`specs/20-employee-performance-and-attendance-profiling.md`](../specs/20-employee-performance-and-attendance-profiling.md).
