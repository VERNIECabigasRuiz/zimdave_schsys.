# Schools & Principal Assignment — Design

Date: 2026-10-16
Status: Approved in conversation, pending written-spec review

## Goal

Let the Administrator create schools and assign a Principal account to a school for a
specific school year. One ZIM School installation serves **many schools** (e.g. a
division). School years are shared by all schools (DepEd calendar is national).

This is piece 1 of 3:

1. **Schools + principal assignment per school year** (this spec)
2. Principal creates teacher accounts for their school (future spec)
3. Teacher enrols students — requires Sections (future spec)

## Roles and authority

| Role | Can do (overall system) | In this spec |
|------|-------------------------|--------------|
| Administrator | Everything; only one who creates schools and school years | All pages below |
| Principal | Creates teachers in their own school (piece 2) | No access to these pages |
| Teacher | Enrols students (piece 3) | No access to these pages |

## Data model

### `SchoolYear` (existing, unchanged)

Shared across all schools. Already has name, dates, `current` flag and Quarters 1–4.

### `School` (new, permanent)

| Field | Type | Rules |
|-------|------|-------|
| `schoolId` | string(6) | DepEd School ID, exactly 6 digits, unique |
| `name` | string(150) | required |
| `type` | string enum | `elementary`, `junior_high`, `senior_high`, `integrated` |
| `region` | string(100) | required |
| `division` | string(100) | required |
| `district` | string(100) | optional |
| `address` | string(255) | optional |
| `active` | bool | default true; schools are deactivated, never deleted |

### `SchoolAssignment` (new, yearly)

One row = a user works at a school in a school year, in a role.

| Field | Type | Rules |
|-------|------|-------|
| `school` | ManyToOne School | required |
| `schoolYear` | ManyToOne SchoolYear | required, cascade delete with school year |
| `user` | ManyToOne User | required |
| `role` | string | `ROLE_PRINCIPAL` now; `ROLE_TEACHER` reserved for piece 2 |
| `createdAt` | datetime_immutable | set on construct |

Constraints:

- **Unique (`user`, `schoolYear`)** — a person belongs to one school per school year.
- **One principal per school per school year** — enforced in validation/service
  (cannot be a plain unique index because teachers share the table later).
- The user's account role must equal the assignment `role`.
- Only **active** users and **active** schools can be newly assigned.

Mid-year change of principal: **simply replace** the assignment's user. No dated
history; the Activity Log records the change ("Replaced principal of X (SY): A → B").

## Screens (all `#[IsGranted(ROLE_ADMIN)]`)

### Schools list — `GET /admin/schools` (`admin_schools`)

- New sidebar item "Schools" in `templates/admin/_layout.html.twig`.
- School-year selector (query `?sy=<id>`), defaults to the current school year.
- Table columns: School ID, Name, Type, District / Division, Principal (selected SY), Status, actions.
- Principal cell: principal's full name with **Change** / **Remove**, or an
  **Assign principal** button when none.
- Actions: **+ New School**, Edit, Deactivate/Activate.
- **Copy principals from SY <previous>** button — shown only when the selected SY has no
  principal assignments and a previous SY exists.
- Empty state when no school year exists yet: prompt to create one first.

### School form — `/admin/schools/new`, `/admin/schools/{id}/edit`

`SchoolFormType` with the School fields. Duplicate School ID shows a validation error.

### Assign / change principal — `/admin/schools/{id}/principal?sy=<id>` (GET, POST)

- Form with one dropdown: active users with role Principal who have no assignment in
  that SY (plus the currently assigned one when changing).
- Saving creates the assignment, or replaces the user on the existing one.
- Logged to Activity Log.

### Remove principal — `POST /admin/schools/{id}/principal/remove?sy=<id>`

CSRF-protected (`IsCsrfTokenValid`), deletes the principal assignment, logged.

### Copy from previous year — `POST /admin/schools/copy-principals?sy=<id>`

- CSRF-protected.
- Source = the school year immediately before the selected one (by start date).
- Copies each principal assignment whose school and user are still active and that
  would not violate a constraint; skips the rest.
- Flash summary: "Copied N principal assignments. Skipped M: <reasons>."
- Logged once to Activity Log.

### Toggle school active — `POST /admin/schools/{id}/toggle` (CSRF-protected, logged)

Deactivating a school keeps its existing assignments (history) but it can't receive
new ones.

### Changes to existing pages

- **Accounts list:** principal accounts show their current-SY school (or "Unassigned").
- **Dashboard:** card "Schools without a principal (current SY)" with a count linking
  to the Schools list.

## Components

- `src/Entity/School.php`, `src/Repository/SchoolRepository.php`
- `src/Entity/SchoolAssignment.php`, `src/Repository/SchoolAssignmentRepository.php`
- `src/Form/SchoolFormType.php`, `src/Form/PrincipalAssignmentFormType.php`
- `src/Service/PrincipalAssigner.php` — assign / replace / remove / copy-from-previous;
  holds the business rules so the controller stays thin and rules are testable.
- `src/Controller/Admin/SchoolController.php`
- `templates/admin/schools/index.html.twig`, `form.html.twig`, `assign_principal.html.twig`
- Doctrine migration via `make:migration` for `school` and `school_assignment`.

## Error handling

- Rule violations from `PrincipalAssigner` (already assigned elsewhere, wrong role,
  inactive school/user, school already has a principal) surface as form errors or
  flash errors — never a 500.
- Unknown `sy` id → 404. Missing current SY → empty state, no crash.

## Testing (`WebTestCase` + `KernelTestCase`)

- Create school; edit school; duplicate School ID rejected; invalid School ID format rejected.
- Assign principal; change principal (replaced, logged); remove principal.
- Second principal for same school + SY blocked.
- Same principal at two schools in same SY blocked.
- Non-principal account cannot be assigned.
- Copy from previous year: copies valid, skips inactive/conflicting, summary correct.
- Principal and teacher accounts get 403 on all `/admin/schools` routes.

## Out of scope

- Principal's teacher-management page (piece 2).
- Sections, enrolment (piece 3).
- Dated history of principal changes.
- School-level admin role.
