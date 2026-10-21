# Plans, Principal Area & School Teachers — Design

Date: 2026-10-16
Status: Approved in conversation, pending written-spec review
Builds on: `2026-10-16-schools-principal-assignment-design.md`

## Goal

Sell ZIM School by plan. Each school is on a plan that limits how many teachers and
students it may have, with an optional expiry date. Principals get their own area where
they manage the teachers of their school, within the plan's limits.

This is piece 1 of 3:

1. **Plans + principal area + school teachers** (this spec)
2. Student accounts with LRN, created by principal/admin (future spec)
3. Sections, adviser, enrolment; Individual-plan teacher enrolment (future spec)

## Plans

### `Plan` (new, managed by Administrator)

| Field | Type | Rules |
|-------|------|-------|
| `name` | string(50) | required, unique |
| `teachersPerSeat` | int | required, ≥ 1 |
| `studentsPerSeat` | int | required, ≥ 1 |
| `includesPrincipal` | bool | default true |

Seeded by the migration:

| Name | Teachers/seat | Students/seat | Includes principal |
|------|---------------|---------------|--------------------|
| Basic | 15 | 1000 | yes |
| Individual | 1 | 65 | no |

A plan can be deleted only while no school uses it.

### `School` additions

| Field | Type | Rules |
|-------|------|-------|
| `plan` | ManyToOne Plan | required; existing schools get Basic in the migration |
| `seats` | int | required, ≥ 1, default 1 |
| `planValidUntil` | date, nullable | null = no expiry |

Derived (on `School`): `getTeacherLimit() = plan.teachersPerSeat × seats`,
`getStudentLimit() = plan.studentsPerSeat × seats`,
`isPlanExpired(today) = planValidUntil !== null && planValidUntil < today`.

## Rules — `App\Service\PlanLimits`

One service, used by every action that adds people, for **every role including Administrator**.

- `assertCanAddTeacher(School $school, SchoolYear $sy): void` — throws `PlanLimitException`
  (user-facing message) when:
  - plan expired: "The plan of {school} expired on {M j, Y}. Contact the administrator to renew."
  - at limit: "The {plan} plan allows {limit} teacher(s) ({used} used). Contact the administrator to upgrade."
- `countTeachers(School, SchoolYear): int` — active users with role `ROLE_TEACHER` that have a
  `SchoolAssignment` (role `ROLE_TEACHER`) for that school and school year.
- Inactive school also blocks adding: "This school is inactive."
- Lowering a plan/seats below usage never removes anyone; it only blocks additions.
- Teacher accounts created on the admin's existing Accounts page have no school, so they
  use no slot until assigned to a school (which goes through `PlanLimits`).
- Student limit is stored now and enforced in pieces 2 and 3. In piece 3, on plans
  without a principal, each teacher's own section is also capped at `studentsPerSeat`.

Principal assignment (existing `PrincipalAssigner::assign` and copy) additionally refuses
schools whose plan has `includesPrincipal = false`:
"The {plan} plan does not include a principal."

## Access — `SchoolVoter`

Attribute `SCHOOL_MANAGE` on a `School`:

- Administrator: always.
- Principal: only the school of their principal `SchoolAssignment` in the **current** school year.
- Everyone else: denied.

A principal's school: `SchoolAssignmentRepository::findForUser($user, $currentSy)`.

## Screens

### Admin — Plans (`/admin/plans`, sidebar "Plans")

- List: Name, Teachers per seat, Students per seat, Includes principal, Schools on plan.
- New / Edit (`PlanFormType`); Delete (POST, CSRF) only when unused, otherwise flash error.
- All changes logged.

### Admin — Schools page additions

- Columns: **Plan** ("Basic" or "Individual × 2" when seats > 1), **Valid until**
  (date; red "Expired" badge when past; amber badge when within 30 days), **Teachers**
  ("12 / 15", current SY).
- Row action **Teachers** → school teachers page.
- School form: Plan (select), Seats, Valid until (optional date).
- Assign/Change principal hidden when the plan has no principal.

### Admin — Dashboard

Card "Plans expired or expiring in 30 days" (count of active schools) → Schools page.

### School Teachers page (`/schools/{id}/teachers`, `#[IsGranted('SCHOOL_MANAGE', 'school')]`)

Shared by admin (any school) and principal (own school). Always for the **current** school
year; when there is no current school year, show "No current school year." with no actions.

- Header: school name, S.Y., "Teachers {used} / {limit} · {plan} plan · valid until {date}".
- Table of teachers assigned this SY: Name, Username, Status, Last sign-in, actions.
- **+ New teacher** (`/schools/{id}/teachers/new`): full name + username; creates a
  `ROLE_TEACHER` user (temporary password, must change on first sign-in, via the existing
  `TemporaryPasswordGenerator`) and a `SchoolAssignment` (role teacher, current SY);
  shows the existing one-time "credentials" flash.
- **Edit** (name, username) — `/schools/{id}/teachers/{teacher}/edit`.
- **Reset password** (POST, CSRF) — new temporary password, credentials flash.
- **Deactivate / Activate** (POST, CSRF) — activate goes through `PlanLimits`.
- **Bring back teachers from S.Y. {previous}** (POST, CSRF) — shown when the previous SY
  has teacher assignments for this school whose users are not assigned anywhere in the
  current SY. Copies active ones in name order while the limit allows; flash summary of
  added / skipped (inactive, assigned elsewhere, limit reached).
- New / Bring back buttons are disabled with the reason when `PlanLimits` would refuse;
  the server enforces the same rule.
- A teacher route for a user who is not a teacher of this school in the current SY → 404.
- All changes logged.

### Principal area

- `HomeController`: principals are redirected to `/principal`.
- Layout `templates/principal/_layout.html.twig`: same styles as admin; sidebar
  Dashboard · Teachers.
- `/principal` (`principal_dashboard`, `ROLE_PRINCIPAL`): school name, S.Y., plan with
  expiry warning, usage cards **Teachers used / limit** and **Students 0 / limit**
  (students are counted from piece 2 on).
- `/principal/teachers` redirects to `/schools/{own school id}/teachers`, rendered in the
  principal layout when viewed by a principal.
- No school for the current SY → page "You are not assigned to a school for S.Y. {name}.
  Contact the administrator." (or "No current school year." when none).

### Individual teacher (until piece 3)

Admin sets the school to the Individual plan and adds the teacher on the school Teachers
page. The teacher's sign-in still shows the existing placeholder home page.

## Components

- `src/Entity/Plan.php`, `src/Repository/PlanRepository.php`, `src/Form/PlanFormType.php`
- `src/Entity/School.php` (+ plan, seats, planValidUntil, limit helpers), `src/Form/SchoolFormType.php`
- `src/Service/PlanLimits.php`, `src/Service/PlanLimitException.php`
- `src/Security/Voter/SchoolVoter.php`
- `src/Service/PrincipalAssigner.php` (includesPrincipal rule)
- `src/Service/SchoolTeacherManager.php` — create teacher, activate, bring back (uses `PlanLimits`)
- `src/Controller/Admin/PlanController.php`
- `src/Controller/SchoolTeacherController.php` (shared admin/principal)
- `src/Controller/Principal/DashboardController.php`
- Templates: `admin/plans/*`, `school_teachers/*`, `principal/_layout.html.twig`, `principal/dashboard.html.twig`, `principal/not_assigned.html.twig`
- Migration: `plan` table + seed rows, school columns, existing schools → Basic.

## Error handling

- `PlanLimitException` and `AssignmentException` → flash error, redirect; never a 500.
- Principal opening another school's page → 403. Unknown teacher in a school → 404.

## Testing

- `PlanLimits` (KernelTestCase): under limit ok; at limit refused (message); seats multiply;
  expired refused; null expiry ok; inactive users don't count; other SY / other school don't count.
- Plans CRUD; delete blocked when in use.
- School form plan/seats/expiry; list shows plan, "Individual × 2", Expired / expiring badges, teacher usage.
- Assign principal refused for Individual plan (service + hidden button).
- School teachers: admin creates teacher (credentials shown, assignment created);
  principal creates teacher for own school; blocked at limit (principal and admin);
  blocked when expired; deactivate frees a slot; activate blocked at limit; reset password;
  bring back (respects limit, skips inactive/assigned elsewhere).
- Access: principal → other school's teachers page 403; principal → `/admin/*` 403;
  teacher → `/principal` 403; principal with no assignment → not-assigned page.
- Home redirect: principal → `/principal`.
- Dashboard card counts expired + expiring-in-30-days active schools.

## Out of scope

- Students (piece 2), sections/enrolment/Individual teacher pages/school forms (piece 3).
- Per-school limit overrides; payments/invoicing; automatic renewal.
