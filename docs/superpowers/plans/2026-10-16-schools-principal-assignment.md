# Schools & Principal Assignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admin can create schools and assign a Principal account to each school per school year, including copying assignments from the previous year.

**Architecture:** New `School` entity (permanent) and `SchoolAssignment` entity (user + school + school year + role). Business rules live in `App\Service\PrincipalAssigner`; a thin `Admin\SchoolController` drives Twig pages following the existing `Admin\SchoolYearController` pattern (flash + `ActivityLogger` + flush in controller).

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine ORM 3 + Migrations, Twig, Symfony Form/Validator, PHPUnit (`WebTestCase`, `KernelTestCase`), MariaDB (XAMPP).

**Spec:** `docs/superpowers/specs/2026-10-16-schools-principal-assignment-design.md`

## Global Constraints

- All new routes are `#[IsGranted(User::ROLE_ADMIN)]`; route names start with `admin_schools` (NOT `admin_school_` — that prefix is used by the School Year nav highlight).
- School ID: exactly 6 digits, stored as string (leading zeros preserved), unique.
- School types: `elementary` "Elementary", `junior_high` "Junior High School", `senior_high` "Senior High School", `integrated` "Integrated School".
- One principal per school per school year; a user has at most one assignment per school year (DB unique on `user_id, school_year_id`).
- Only active users with role `ROLE_PRINCIPAL` and active schools can receive a new assignment.
- Schools are never deleted, only deactivated.
- Every state change writes one `ActivityLogger::log()` entry; POST actions without a form use `#[IsCsrfTokenValid]`.
- Schema changes via `bin/console make:migration` + `doctrine:migrations:migrate` (run for dev and `--env=test`), never `schema:update`.
- Tests: `php bin/phpunit`. Project is not a git repo — skip commit steps unless the user initialises git.

## Review Focus

1. School ID with leading zeros (`012345`) — must be saved and displayed exactly, not as `12345`. → test in Task 1.
2. Principal's account deactivated after assignment — Schools list must still show the name, marked "(inactive)", and Copy must skip it. → tests in Tasks 2 and 3.
3. Copy POSTed when the target year already has some assignments (double click / stale page) — no duplicates, conflicts reported as skipped. → test in Task 2.
4. `?sy=` pointing to a non-existent school year → 404; no school year exists at all → empty-state message, not a crash. → test in Task 3.
5. Assign POSTed for an inactive school (stale page) → flash error, no assignment created. → test in Task 3.

---

### Task 1: School entity and School CRUD pages

**Files:**
- Create: `src/Entity/SchoolType.php`, `src/Entity/School.php`, `src/Repository/SchoolRepository.php`
- Create: `src/Form/SchoolFormType.php`
- Create: `src/Controller/Admin/SchoolController.php`
- Create: `templates/admin/schools/index.html.twig`, `templates/admin/schools/form.html.twig`
- Create: `migrations/VersionYYYYMMDDHHMMSS.php` (generated)
- Modify: `templates/admin/_layout.html.twig:26` (add nav link after School Year)
- Modify: `tests/Controller/Admin/AdminTestCase.php:25` (add `School::class` to the delete list, before `User::class`)
- Test: `tests/Controller/Admin/SchoolControllerTest.php`

**Interfaces:**
- Produces:
  - `enum SchoolType: string` with cases above and `label(): string`.
  - `School` getters/setters: `getId(): ?int`, `getSchoolId(): string`/`setSchoolId(string)`, `getName()`/`setName()`, `getType(): ?SchoolType`/`setType(?SchoolType)`, `getRegion()`, `getDivision()`, `getDistrict(): ?string`, `getAddress(): ?string`, `isActive(): bool`/`setActive(bool)`. Setters trim and return `static`.
  - `SchoolRepository::findAllOrdered(): list<School>` (by name ASC).
  - Routes: `admin_schools` GET `/admin/schools`; `admin_schools_new` GET|POST `/admin/schools/new`; `admin_schools_edit` GET|POST `/admin/schools/{id}/edit`; `admin_schools_toggle` POST `/admin/schools/{id}/toggle` (CSRF id `"school-" ~ id`).
  - Form submit button labels: "Create school" (new), "Save school" (edit). Field names `school_form[schoolId|name|type|region|division|district|address]`.

- [ ] **Step 1: Write failing tests** in `SchoolControllerTest extends AdminTestCase`, with a helper `createSchool(string $schoolId, string $name): void` that submits the new form (type `junior_high`, region `Region VII`, division `Mandaue City`) and asserts redirect to `/admin/schools`.
  - `testAdminCreatesSchool`: create `303456` "Pagsabungan National High School"; DB row exists with those values and `isActive() === true`; list page contains the name; activity actions contain `Created school 303456 Pagsabungan National High School`.
  - `testSchoolIdKeepsLeadingZeros`: create `012345`; entity `getSchoolId() === '012345'`; list shows `012345`.
  - `testDuplicateSchoolIdRejected`: create `303456` twice; second response 422 and contains "This School ID already exists."
  - `testInvalidSchoolIdRejected`: `12345` and `30345A` each → 422 containing "School ID must be exactly 6 digits."
  - `testAdminEditsSchool`: change name; DB updated; log `Updated school 303456 <new name>`.
  - `testToggleDeactivatesAndReactivates`: POST toggle with token from the list form; `isActive()` false, then true; logs `Deactivated school …` / `Activated school …`.
  - `testNonAdminsAreDenied`: `loginUser` a principal and a teacher (via `createUser`); GET `/admin/schools` and `/admin/schools/new` → 403.

- [ ] **Step 2: Run** `php bin/phpunit tests/Controller/Admin/SchoolControllerTest.php` — Expected: FAIL (route/class not found).

- [ ] **Step 3: Implement entity, repository, enum.** `School`: `#[UniqueEntity(fields: ['schoolId'], message: 'This School ID already exists.')]`; `schoolId` `length: 6, unique: true`, `#[Assert\Regex('/^\d{6}$/', message: 'School ID must be exactly 6 digits.')]`; `name` 150, `region`/`division` 100 NotBlank; `district` 100 nullable; `address` 255 nullable; `type` `#[ORM\Column(enumType: SchoolType::class)]` NotNull; `active` default true.

- [ ] **Step 4: Generate and run migration.** `php bin/console make:migration --no-interaction`, review it creates only `school`, then `php bin/console doctrine:migrations:migrate --no-interaction` and the same with `--env=test`.

- [ ] **Step 5: Implement form, controller, templates, nav.** `SchoolFormType` uses `EnumType` for `type` with `choice_label: fn (SchoolType $t) => $t->label()`. Controller mirrors `SchoolYearController` (constructor-injected repo, `EntityManagerInterface`, `ActivityLogger`; log then flush; flash success; redirect to `admin_schools`). Templates extend `admin/_layout.html.twig` and copy the table/form markup style of `templates/admin/school_years/`. Nav: `_self.nav_link('Schools', path('admin_schools'), route starts with 'admin_schools')`. Task 1's list has columns School ID, Name, Type, District / Division, Status, actions (the Principal column arrives in Task 3).

- [ ] **Step 6: Run** the test file — Expected: PASS. Then `php bin/phpunit` — Expected: whole suite PASS. `php bin/console lint:twig templates/admin` and `lint:container` clean.

---

### Task 2: SchoolAssignment entity and PrincipalAssigner service

**Files:**
- Create: `src/Entity/SchoolAssignment.php`, `src/Repository/SchoolAssignmentRepository.php`
- Create: `src/Service/PrincipalAssigner.php`, `src/Service/AssignmentException.php`, `src/Service/CopyResult.php`
- Modify: `src/Repository/SchoolYearRepository.php` (add `findPrevious`)
- Modify: `tests/Controller/Admin/AdminTestCase.php:25` (add `SchoolAssignment::class` first in the delete list)
- Create: migration (generated)
- Test: `tests/Service/PrincipalAssignerTest.php` (`KernelTestCase`)

**Interfaces:**
- Consumes: `School`, `SchoolType` (Task 1); `SchoolYear`, `User`, `ActivityLogger::log(string)`.
- Produces:
  - `SchoolAssignment(School $school, SchoolYear $schoolYear, User $user, string $role)` constructor (promoted, ORM attributes like `GradingPeriod`); getters; `setUser(User)`; `getCreatedAt()`. `schoolYear` join `onDelete: 'CASCADE'`. `#[ORM\UniqueConstraint(columns: ['user_id', 'school_year_id'])]`.
  - `SchoolAssignmentRepository::findPrincipal(School, SchoolYear): ?SchoolAssignment`, `findForUser(User, SchoolYear): ?SchoolAssignment`, `findPrincipalsBySchool(SchoolYear): array<int, SchoolAssignment>` (keyed by school id), `countPrincipals(SchoolYear): int`.
  - `SchoolYearRepository::findPrevious(SchoolYear): ?SchoolYear` — latest by `startDate` strictly before the given one's.
  - `final class AssignmentException extends \DomainException` (message is user-facing).
  - `final readonly class CopyResult(int $copied, list<string> $skipped)`.
  - `PrincipalAssigner`: `assign(School, SchoolYear, User): SchoolAssignment` (create, or replace user on the existing principal assignment); `remove(School, SchoolYear): void`; `copyFromPrevious(SchoolYear $target): CopyResult`. Service persists/removes and logs but does **not** flush; callers flush.

- [ ] **Step 1: Write failing tests** in `PrincipalAssignerTest` (setUp: boot kernel, clear tables in order SchoolAssignment, ActivityLog, School, SchoolYear, User; helpers to persist a school, a SY with given start year, and users). Each test flushes after the service call.
  - `testAssignsPrincipal`: assignment exists with role `ROLE_PRINCIPAL`; log `Assigned <full name> as principal of <school name> (2026-2027)`.
  - `testReplacesPrincipalInSameYear`: assign A then B; exactly one assignment for school+SY, user B; log `Replaced principal of <school> (2026-2027): <A> → <B>`.
  - `testRejectsNonPrincipalAccount`: teacher → `AssignmentException` "Only accounts with the Principal role can be assigned."
  - `testRejectsInactiveUser` → "This account is inactive."; `testRejectsInactiveSchool` → "This school is inactive."
  - `testRejectsPrincipalAlreadyAssignedElsewhereSameYear` → "<name> is already assigned to <other school> for 2026-2027."
  - `testAllowsSamePrincipalDifferentYears`: same user, same school, two SYs → two assignments.
  - `testRemove`: assignment gone; log `Removed <name> as principal of <school> (2026-2027)`.
  - `testCopyFromPrevious`: 2025-2026 has three schools assigned: one normal, one whose user is now inactive, one whose school is now inactive. Copy into 2026-2027 → `copied === 1`, `skipped` has 2 entries naming the school and reason; log `Copied 1 principal assignment(s) from 2025-2026 to 2026-2027`.
  - `testCopySkipsWhenTargetAlreadyAssigned` (Review Focus 3): target already has school X assigned to C; copy → X keeps C, counted in `skipped`; running copy twice creates no duplicates.
  - `testCopyWithoutPreviousYearThrows` → `AssignmentException` "There is no previous school year to copy from."

- [ ] **Step 2: Run** `php bin/phpunit tests/Service/PrincipalAssignerTest.php` — Expected: FAIL (classes missing).

- [ ] **Step 3: Implement entity + repositories**, then `make:migration --no-interaction`, review it only adds `school_assignment` (with FKs and the unique index), migrate dev and `--env=test`.

- [ ] **Step 4: Implement `PrincipalAssigner`.** `assign` checks in order: user role, user active, school active, user's other assignment in that SY (ignore if it is this school's principal assignment). `copyFromPrevious` iterates `findPrincipalsBySchool($previous)` and, for each, skips with reason if school inactive / user inactive / user no longer principal / target already has a principal for that school / user already assigned in target; otherwise persists a new assignment. Track users assigned during the loop (not yet flushed) so the uniqueness check sees them.

- [ ] **Step 5: Run** the test file and then `php bin/phpunit` — Expected: PASS.

---

### Task 3: Principal column, assign/change/remove and copy on the Schools page

**Files:**
- Create: `src/Form/PrincipalAssignmentFormType.php`, `templates/admin/schools/assign_principal.html.twig`
- Modify: `src/Controller/Admin/SchoolController.php`, `templates/admin/schools/index.html.twig`
- Modify: `src/Repository/UserRepository.php` (add `findAssignablePrincipals`)
- Test: `tests/Controller/Admin/SchoolPrincipalTest.php`

**Interfaces:**
- Consumes: `PrincipalAssigner`, `AssignmentException`, `CopyResult`, `SchoolAssignmentRepository` (Task 2); `SchoolYearRepository::findCurrent()`, `findPrevious()`.
- Produces:
  - `UserRepository::findAssignablePrincipals(SchoolYear $sy, ?User $include = null): list<User>` — active `ROLE_PRINCIPAL` users with no assignment in `$sy`, plus `$include`; ordered by full name.
  - `PrincipalAssignmentFormType` — one unmapped-style field `principal` (`EntityType` of `User`, `choices` from option `principals`, `choice_label: 'fullName'`, NotNull "Select a principal."); submit label "Save principal".
  - Routes: `admin_schools_principal` GET|POST `/admin/schools/{id}/principal`; `admin_schools_principal_remove` POST `/admin/schools/{id}/principal/remove` (CSRF id `"school-principal-" ~ id`); `admin_schools_copy_principals` POST `/admin/schools/copy-principals` (CSRF id `copy-principals`). All take `#[MapQueryParameter] ?int $sy`.
  - Private controller helper `resolveSchoolYear(?int $sy): ?SchoolYear` — given id → find or `createNotFoundException`; null → `findCurrent()`.

- [ ] **Step 1: Write failing tests** (`extends AdminTestCase`; helpers persist SYs `2025-2026` and current `2026-2027`, schools, principal users directly via `$this->em`).
  - `testListShowsAssignButtonAndPrincipalName`: unassigned school shows "Assign principal"; after assigning via the form, list shows principal's full name and "Change".
  - `testAssignViaForm`: GET assign page for current SY, dropdown lists only unassigned active principals (assigned-elsewhere and inactive ones absent, teachers absent); submit → redirect to `/admin/schools?sy=<id>`, flash "… is now the principal of …".
  - `testChangePrincipal`: assign A, then submit B → one assignment with B.
  - `testRemovePrincipal`: POST remove with token from list → assignment gone, flash success.
  - `testSchoolYearSelector`: `?sy=<2025 id>` shows 2025-2026 assignments, not 2026-2027's.
  - `testUnknownSchoolYearIs404` (Review Focus 4): `/admin/schools?sy=999999` → 404.
  - `testNoSchoolYearShowsEmptyState` (Review Focus 4): delete all SYs; list → 200 containing "Create a school year first" and a link to `admin_school_year_new`.
  - `testAssignToInactiveSchoolShowsError` (Review Focus 5): open assign form, deactivate school in DB, submit → redirect with flash error "This school is inactive.", no assignment.
  - `testInactivePrincipalShownAsInactive` (Review Focus 2): assigned user later deactivated → list shows name followed by "(inactive)".
  - `testCopyButtonAndCopy`: 2026-2027 empty and 2025-2026 has an assignment → list shows "Copy principals from SY 2025-2026"; POST copy → flash "Copied 1 principal assignment(s)."; button no longer shown.
  - `testCopyButtonHiddenWithoutPreviousYear`.
  - `testPrincipalCannotAccessAssignRoutes`: logged-in principal → 403 on GET assign page and POST copy.

- [ ] **Step 2: Run** `php bin/phpunit tests/Controller/Admin/SchoolPrincipalTest.php` — Expected: FAIL.

- [ ] **Step 3: Implement.** Index passes `school_years` (for the `<select>` in a GET form auto-submitting on change), `school_year`, `principals` (`findPrincipalsBySchool`), `previous_year` (only when `countPrincipals($sy) === 0`). Assign action catches `AssignmentException` → `addFlash('error', $e->getMessage())` and redirect back to the list; on success flush then flash. Copy flash: `Copied N principal assignment(s).` plus, if skipped, a second `warning` flash `Skipped: <reason>; <reason>`. Assign/Change/Remove buttons hidden for inactive schools.

- [ ] **Step 4: Run** the test file, then `php bin/phpunit`, `lint:twig templates/admin` — Expected: PASS / clean.

---

### Task 4: Accounts list school column and dashboard card

**Files:**
- Modify: `src/Controller/Admin/UserAccountController.php:172-195` (`renderList`), `templates/admin/accounts/index.html.twig:66`
- Modify: `src/Controller/Admin/DashboardController.php`, `templates/admin/dashboard.html.twig`
- Modify: `src/Repository/SchoolRepository.php` (add `countActiveWithoutPrincipal`)
- Modify: `src/Repository/SchoolAssignmentRepository.php` (add `findSchoolNamesByUser`)
- Test: add cases to `tests/Controller/Admin/UserAccountControllerTest.php` and `tests/Controller/Admin/DashboardControllerTest.php`

**Interfaces:**
- Consumes: Task 2 repositories, `SchoolYearRepository::findCurrent()`.
- Produces: `SchoolAssignmentRepository::findSchoolNamesByUser(SchoolYear): array<int, string>` (user id → school name); `SchoolRepository::countActiveWithoutPrincipal(SchoolYear): int`.

- [ ] **Step 1: Write failing tests.**
  - `testPrincipalRowShowsCurrentSchool` (accounts): principal assigned for current SY → `/admin/accounts/principals` row contains school name; unassigned principal row contains "Unassigned"; teacher rows show neither.
  - `testDashboardShowsSchoolsWithoutPrincipal`: current SY, 3 active schools (1 assigned), 1 inactive unassigned → dashboard contains "Schools without a principal" and `2`, linking to `/admin/schools`.
  - `testDashboardWithoutCurrentSchoolYear`: no SY → dashboard 200, card absent.

- [ ] **Step 2: Run** both test files — Expected: new tests FAIL.

- [ ] **Step 3: Implement.** `renderList` passes `school_names` (empty array when no current SY); template appends ` · <school>` or ` · Unassigned` (muted) for principal rows only. Dashboard passes `schools_without_principal` (null when no current SY) and renders a stat card matching existing cards.

- [ ] **Step 4: Run** `php bin/phpunit` (full suite) and `lint:twig`, `lint:container` — Expected: all PASS.

- [ ] **Step 5: Manual check** at `http://localhost:8081/admin/schools`: create a school, assign a principal, switch SY, copy, check dashboard card and accounts list.
