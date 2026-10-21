# Plans, Principal Area & School Teachers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Schools are sold on plans (Basic, Individual) that limit teachers per school with an optional expiry; principals get their own area to manage their school's teachers within those limits.

**Architecture:** New `Plan` entity referenced by `School` (+ seats, planValidUntil). All "may we add a teacher?" rules live in `App\Service\PlanLimits`; teacher creation/activation/bring-back in `App\Service\SchoolTeacherManager`. One shared `SchoolTeacherController` serves admin and principal, guarded by a `SchoolVoter` (`SCHOOL_MANAGE`). Principal area has its own layout and dashboard.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine ORM 3 + Migrations, Twig, Form/Validator, Security voters, PHPUnit (`WebTestCase`, `KernelTestCase`), MariaDB 10.4 (XAMPP).

**Spec:** `docs/superpowers/specs/2026-10-16-plans-principal-teachers-design.md`

## Global Constraints

- Plan seeds: **Basic** 15 teachers/seat, 1000 students/seat, includes principal; **Individual** 1 teacher/seat, 65 students/seat, no principal.
- Limits = per-seat number × `School::seats`; apply to every role including Administrator.
- Teacher slot = active `ROLE_TEACHER` user with a `SchoolAssignment` (role `ROLE_TEACHER`) for that school and school year.
- Expired = `planValidUntil < today` (the date itself is still valid); null = never expires.
- Exact messages:
  - `The plan of {school} expired on {M j, Y}. Contact the administrator to renew.`
  - `The {plan} plan allows {limit} teacher(s) ({used} used). Contact the administrator to upgrade.`
  - `This school is inactive.`
  - `The {plan} plan does not include a principal.`
- Admin routes under `/admin` (existing `access_control`); shared routes under `/schools/{id}/teachers`; principal routes under `/principal` with `#[IsGranted(User::ROLE_PRINCIPAL)]`.
- Every state change logs via `ActivityLogger::log()`; POST actions without a form use `#[IsCsrfTokenValid]`; forged token → redirect (app convention).
- Schema via `php bin/console make:migration` (review/adjust data lines) + `doctrine:migrations:migrate` for dev and `--env=test`.
- MariaDB must be running for tests (`php bin/phpunit`, ~3–4 min). No git: skip commit steps. No python: use Edit, not sed, for backslash paths.

## Review Focus

1. A principal reassigned to another school (or removed) — must lose access to the old school's teachers page immediately. → voter test in Task 3.
2. `planValidUntil` equal to today — still valid; yesterday — expired. → `PlanLimits` test in Task 3.
3. Admin lowers seats/plan below current usage — teachers stay, page shows e.g. `16 / 15`, adding is blocked. → test in Task 4.
4. Principal viewing the shared teachers page must see the principal sidebar, not the admin one. → test in Task 5.
5. School switched to a no-principal plan while a principal is assigned for the current SY — refused with `Remove the principal of {school} for {SY} before choosing the {plan} plan.` → test in Task 2.

---

### Task 1: Plan entity, migration with seeds, Plans admin pages

**Files:**
- Create: `src/Entity/Plan.php`, `src/Repository/PlanRepository.php`, `src/Form/PlanFormType.php`, `src/Controller/Admin/PlanController.php`, `templates/admin/plans/index.html.twig`, `templates/admin/plans/form.html.twig`
- Modify: `src/Entity/School.php` (plan, seats, planValidUntil fields + getters/setters only), `templates/admin/_layout.html.twig` (nav "Plans" after "Schools"), `tests/Controller/Admin/DashboardControllerTest.php` (sidebar list + adminPages), `tests/Controller/Admin/AdminTestCase.php` (`school()` helper sets Basic plan; `Plan::class` deleted after `School::class`), `tests/Service/PrincipalAssignerTest.php` (its `school()` helper sets a plan)
- Create: migration
- Test: `tests/Controller/Admin/PlanControllerTest.php`

**Interfaces:**
- Produces:
  - `Plan`: `getId()`, `getName()/setName(string)`, `getTeachersPerSeat(): int/set…(int)`, `getStudentsPerSeat(): int/set…(int)`, `includesPrincipal(): bool/setIncludesPrincipal(bool)`. `#[UniqueEntity('name', message: 'This plan already exists.')]`, per-seat fields `#[Assert\Positive]`.
  - `PlanRepository::findByName(string): ?Plan`, `countSchoolsByPlan(): array<int, int>` (plan id → school count).
  - `School`: `getPlan(): ?Plan/setPlan(?Plan)` (`#[Assert\NotNull]`), `getSeats(): int/setSeats(int)` (default 1, `#[Assert\Positive]`), `getPlanValidUntil(): ?\DateTimeImmutable/setPlanValidUntil(?…)`.
  - Routes: `admin_plans` GET `/admin/plans`; `admin_plans_new`, `admin_plans_edit` (`/admin/plans/{id}/edit`); `admin_plans_delete` POST `/admin/plans/{id}/delete` (CSRF id `"plan-" ~ id`). Form fields `plan_form[name|teachersPerSeat|studentsPerSeat|includesPrincipal]`, buttons "Create plan" / "Save plan".
  - Test helper `AdminTestCase::plan(string $name = 'Basic'): Plan` — returns the seeded plan by name (seeds are not deleted by test cleanup).

- [ ] **Step 1: Write failing tests** in `PlanControllerTest extends AdminTestCase`:
  - `testSeededPlansAreListed`: GET `/admin/plans` → rows "Basic" with `15` and `1000` and "Yes"; "Individual" with `1`, `65`, "No".
  - `testAdminCreatesPlan`: create "Standard" 40 / 2500 / includes principal → saved; log `Created plan Standard`.
  - `testDuplicatePlanNameRejected`: "Basic" → 422 "This plan already exists."
  - `testAdminEditsPlan`: Basic teachers 15 → 20; log `Updated plan Basic`; then restore to 15 in the test (seeds are shared).
  - `testDeleteUnusedPlan`: create "Trial", delete via list button → gone; log `Deleted plan Trial`.
  - `testDeletePlanInUseRefused`: school on Basic; delete Basic → flash error `Basic is used by 1 school(s) and cannot be deleted.`; still exists.
  - `testPrincipalCannotOpenPlans`: principal → GET `/admin/plans` 403.

- [ ] **Step 2: Run** `php bin/phpunit tests/Controller/Admin/PlanControllerTest.php` → FAIL (class/route missing).

- [ ] **Step 3: Implement entity, repository, School fields; generate migration.** After `make:migration`, edit the migration so it: creates `plan`; inserts Basic and Individual; adds `school.plan_id` **nullable**, `seats INT NOT NULL DEFAULT 1`, `plan_valid_until DATE DEFAULT NULL`; `UPDATE school SET plan_id = (SELECT id FROM plan WHERE name = 'Basic')`; then makes `plan_id` NOT NULL and adds the FK + index. `down()` reverses. Migrate dev and `--env=test`; `doctrine:schema:validate` must say in sync.

- [ ] **Step 4: Implement form, controller, templates, nav; update test helpers** (`AdminTestCase::school()` and `PrincipalAssignerTest::school()` call `setPlan()` with the seeded Basic; add `Plan` to cleanup? **No** — seeds must survive; cleanup deletes only plans whose name is not Basic/Individual: `DELETE FROM App\Entity\Plan p WHERE p.name NOT IN ('Basic', 'Individual')`, after deleting schools). Update sidebar expectation to include `'Plans'` after `'Schools'` and add `/admin/plans` to `adminPages()`.

- [ ] **Step 5: Run** the test file, then `php bin/phpunit` → all PASS; `lint:twig templates`, `lint:container` OK.

---

### Task 2: School plan fields on form and list; no-principal rule

**Files:**
- Modify: `src/Entity/School.php` (limit helpers), `src/Form/SchoolFormType.php`, `templates/admin/schools/form.html.twig`, `templates/admin/schools/index.html.twig`, `src/Controller/Admin/SchoolController.php` (form-level principal check, pass teacher counts), `src/Service/PrincipalAssigner.php`
- Test: `tests/Controller/Admin/SchoolControllerTest.php`, `tests/Controller/Admin/SchoolPrincipalTest.php`, `tests/Service/PrincipalAssignerTest.php`

**Interfaces:**
- Consumes: `Plan`, `School::getPlan/getSeats/getPlanValidUntil` (Task 1).
- Produces:
  - `School::getTeacherLimit(): int`, `getStudentLimit(): int`, `isPlanExpired(\DateTimeImmutable $today): bool`, `getPlanLabel(): string` (`Basic`, or `Individual × 2` when seats > 1).
  - `SchoolAssignmentRepository::countTeachersBySchool(SchoolYear): array<int, int>` (school id → active teacher count) — used here for the list column and by Task 3's `PlanLimits` for a single school via `countTeachers()`.
  - Form fields `school_form[plan|seats|planValidUntil]`.

- [ ] **Step 1: Write failing tests.**
  - `SchoolControllerTest::testSchoolPlanFieldsSaved`: create school with plan Individual, seats 2, valid until `2027-04-30` → saved; list row shows `Individual × 2` and `Apr 30, 2027`.
  - `SchoolControllerTest::testExpiryBadges`: schools valid until yesterday → row contains `Expired`; in 10 days → `Expires soon`; null → `—`.
  - `SchoolPrincipalTest::testTeacherUsageColumn`: current SY, Basic school with 2 active + 1 inactive assigned teachers → row contains `2 / 15`.
  - `SchoolPrincipalTest::testNoAssignButtonOnIndividualPlan`: Individual school → no "Assign principal" in its row.
  - `PrincipalAssignerTest::testRejectsSchoolWithoutPrincipalPlan` → `AssignmentException` `The Individual plan does not include a principal.`; copy skips it with that reason.
  - `SchoolControllerTest::testCannotSwitchToNoPrincipalPlanWhilePrincipalAssigned` (Review Focus 5): current SY, school with principal; edit plan → Individual → 422 `Remove the principal of {name} for 2026-2027 before choosing the Individual plan.`; plan unchanged.

- [ ] **Step 2: Run** those files → new tests FAIL.

- [ ] **Step 3: Implement.** Helpers on `School`; form fields (`EntityType` Plan ordered by name, `IntegerType` seats, optional `DateType` single_text); list columns Plan / Valid until / Teachers (Teachers column only when a school year is selected); `PrincipalAssigner::assertAssignable` checks `includesPrincipal()` first-after-role; edit action checks after `isValid()`: if `!plan.includesPrincipal()` and current SY has a principal assignment → `FormError` on `plan`. "Expires soon" = within 30 days.

- [ ] **Step 4: Run** the three test files then full suite → PASS.

---

### Task 3: PlanLimits service and SchoolVoter

**Files:**
- Create: `src/Service/PlanLimits.php`, `src/Service/PlanLimitException.php`, `src/Security/Voter/SchoolVoter.php`
- Test: `tests/Service/PlanLimitsTest.php`, `tests/Security/SchoolVoterTest.php` (both `KernelTestCase`)

**Interfaces:**
- Consumes: Task 2 helpers and `SchoolAssignmentRepository`, `SchoolYearRepository::findCurrent()`.
- Produces:
  - `final class PlanLimitException extends \DomainException`.
  - `PlanLimits::countTeachers(School, SchoolYear): int`; `assertCanAddTeacher(School, SchoolYear): void` (checks in order: school inactive, expired, at limit); `canAddTeacher(School, SchoolYear): ?string` (null = allowed, else the message — used by templates to disable buttons). Uses an injected `ClockInterface` (`symfony/clock`, `#[Autowire]` not needed) for "today" so tests can freeze time.
  - `SchoolVoter` with `public const MANAGE = 'SCHOOL_MANAGE'`; supports `School` subjects.
  - `SchoolAssignmentRepository::findSchoolOf(User, SchoolYear, string $role): ?School`.

- [ ] **Step 1: Write failing tests.** `PlanLimitsTest` (Basic school, current SY; teachers created via entities):
  - `testAllowsUnderLimit`; `testRefusesAtLimit` (15 active teachers → exact limit message with `15 used`);
  - `testSeatsMultiply` (Individual × 2: 2nd teacher allowed, 3rd refused with `allows 2 teacher(s) (2 used)`);
  - `testInactiveTeachersDoNotCount`; `testOtherSchoolYearAndSchoolDoNotCount`;
  - `testExpiredRefused` (valid until yesterday → expiry message with date) and `testValidOnExpiryDay` (valid until today → allowed) (Review Focus 2) — freeze the clock with `Symfony\Component\Clock\Clock::set(new MockClock('2026-10-16'))` or `ClockSensitiveTrait::mockTime()`;
  - `testNoExpiryAllowed`; `testInactiveSchoolRefused`.
  `SchoolVoterTest`:
  - `testAdminCanManageAnySchool`; `testPrincipalCanManageOwnSchool`;
  - `testPrincipalCannotManageOtherSchool`;
  - `testPrincipalLosesAccessWhenReassigned` (Review Focus 1: assignment's school changed → old school denied, new allowed);
  - `testPrincipalOfPreviousYearOnlyIsDenied`; `testTeacherDenied`.

- [ ] **Step 2: Run** both files → FAIL. If `symfony/clock` is missing, `composer require symfony/clock` (check `composer.json` first).

- [ ] **Step 3: Implement** service, exception, repository method, voter (`Voter` subclass, `#[AsTaggedItem]` not needed — autoconfigured).

- [ ] **Step 4: Run** both files then full suite → PASS.

---

### Task 4: School Teachers page (shared admin/principal) and teacher manager

**Files:**
- Create: `src/Service/SchoolTeacherManager.php`, `src/Service/BringBackResult.php`, `src/Form/TeacherFormType.php`, `src/Controller/SchoolTeacherController.php`, `templates/school_teachers/index.html.twig`, `templates/school_teachers/form.html.twig`
- Modify: `templates/admin/schools/index.html.twig` (row button "Teachers")
- Test: `tests/Controller/SchoolTeacherControllerTest.php` (extends `App\Tests\Controller\Admin\AdminTestCase`)

**Interfaces:**
- Consumes: `PlanLimits`, `SchoolVoter::MANAGE`, `TemporaryPasswordGenerator::generate(): string`, `UserPasswordHasherInterface`, `SchoolYearRepository::findCurrent()/findPrevious()`.
- Produces:
  - `SchoolTeacherManager::create(School, SchoolYear, User $teacher): string` (sets role teacher, hashes generated password, mustChangePassword true, persists user + assignment, logs `Created teacher {username} for {school}`; returns the temporary password; throws `PlanLimitException`); `activate(School, SchoolYear, User): void` (limit-checked, logs); `deactivate(User): void` (logs); `resetPassword(User): string` (logs); `bringBack(School, SchoolYear $current): BringBackResult` (`final readonly class BringBackResult(int $added, list<string> $skipped)`; previous SY via `findPrevious`; candidates = previous-SY teacher assignments of this school, by full name; skip inactive, already assigned in current SY, and once `canAddTeacher` refuses add `"{name}: limit reached"`); `findTeacher(School, SchoolYear, int $userId): ?User`. Manager does not flush.
  - Routes (class `#[Route('/schools/{id}/teachers', requirements: ['id' => '\d+'])]`, each action `#[IsGranted(SchoolVoter::MANAGE, 'school')]`): `school_teachers` GET; `school_teachers_new` GET|POST `/new`; `school_teachers_edit` GET|POST `/{teacher}/edit`; `school_teachers_reset_password`, `school_teachers_toggle` POST (CSRF id `"teacher-" ~ teacher.id`); `school_teachers_bring_back` POST `/bring-back` (CSRF id `"bring-back-" ~ school.id`).
  - `TeacherFormType`: `teacher_form[fullName|username]`, buttons "Create teacher" / "Save teacher". `UniqueEntity` on User already rejects taken usernames.
  - Template picks layout: `{% extends is_granted('ROLE_ADMIN') ? 'admin/_layout.html.twig' : 'principal/_layout.html.twig' %}` — Task 5 creates the principal layout; until then principal tests in this task are written but Task 5 makes them pass (mark them in Step 2's expected output).

- [ ] **Step 1: Write failing tests** (admin logged in unless stated; current SY 2026-2027, Basic school "Pagsabungan NHS"):
  - `testAdminCreatesTeacher`: new teacher → redirect to list; `.credentials` shows username and a password; user has `ROLE_TEACHER`, `mustChangePassword`; assignment exists for current SY; header shows `Teachers 1 / 15`.
  - `testCreateBlockedAtLimit`: Individual × 1 school with one teacher → "+ New teacher" rendered disabled with the limit message; direct POST → redirect with `.alert--error` limit message, no new user.
  - `testCreateBlockedWhenExpired`: valid until yesterday → POST refused with expiry message.
  - `testLoweredLimitKeepsTeachers` (Review Focus 3): Basic, 3 teachers, then admin sets plan Individual seats 1 (no principal assigned) → page lists 3 teachers and `3 / 1`; create refused.
  - `testDeactivateFreesSlotAndActivateChecksLimit`: Individual × 1 with teacher A; deactivate A → `0 / 1`; create B; activate A → error limit message, A stays inactive.
  - `testResetPassword`: credentials flash shows new password; `mustChangePassword` true.
  - `testEditTeacher`: rename → saved; duplicate username → 422 `This username is already taken.`
  - `testBringBack`: 2025-2026 had teachers A (active), B (inactive), C (assigned to another school in 2026-2027) → button `Bring back teachers from S.Y. 2025-2026`; POST → A added; flash `Brought back 1 teacher(s).` and warning listing B and C reasons.
  - `testBringBackStopsAtLimit`: Individual × 1, previous year A and B → only one added, other skipped `limit reached`.
  - `testTeacherOfAnotherSchoolIs404`: edit URL with a teacher from another school → 404.
  - `testPrincipalManagesOwnSchool` *(passes after Task 5)*: principal of the school creates a teacher → success.
  - `testPrincipalCannotOpenOtherSchool`: principal of school A → GET school B teachers → 403.
  - `testNoCurrentSchoolYear`: no current SY → 200 with `No current school year.` and no "+ New teacher".

- [ ] **Step 2: Run** `php bin/phpunit tests/Controller/SchoolTeacherControllerTest.php` → FAIL (routes missing).

- [ ] **Step 3: Implement** manager, form, controller (thin: resolve current SY, delegate, catch `PlanLimitException` → `addFlash('error')` + redirect; `credentials` flash with `title` "Teacher created"/"Password reset"), templates (header usage line; table like `admin/accounts/index.html.twig`; disabled buttons with `title`/visible reason from `PlanLimits::canAddTeacher()`), "Teachers" button on Schools rows. Create a minimal `templates/principal/_layout.html.twig` only if needed to render; Task 5 completes it.

- [ ] **Step 4: Run** the file (principal-own-school test may still fail until Task 5 — acceptable only for that test), then full suite.

---

### Task 5: Principal area

**Files:**
- Create: `src/Controller/Principal/DashboardController.php`, `templates/principal/_layout.html.twig`, `templates/principal/dashboard.html.twig`, `templates/principal/not_assigned.html.twig`
- Modify: `src/Controller/HomeController.php`
- Test: `tests/Controller/Principal/PrincipalAreaTest.php`

**Interfaces:**
- Consumes: `SchoolAssignmentRepository::findSchoolOf(User, SchoolYear, 'ROLE_PRINCIPAL')`, `PlanLimits::countTeachers()`, `School` helpers.
- Produces: routes `principal_dashboard` GET `/principal`, `principal_teachers` GET `/principal/teachers` (302 → `school_teachers` of own school); both `#[IsGranted(User::ROLE_PRINCIPAL)]`. Layout sidebar links: "Dashboard" (`principal_dashboard`), "Teachers" (`principal_teachers`), with the same user box / logout / change-password as the admin layout.

- [ ] **Step 1: Write failing tests:**
  - `testPrincipalIsSentToPrincipalArea`: GET `/` → redirect `/principal`.
  - `testDashboardShowsSchoolAndUsage`: Basic school, valid until 2027-04-30, 2 teachers → contains school name, `S.Y. 2026-2027`, `Basic`, `Apr 30, 2027`, card `Teachers` = `2 / 15`, card `Students` = `0 / 1,000`.
  - `testExpiredPlanWarning`: valid until yesterday → `.alert--error` contains `expired`.
  - `testNotAssignedPage`: principal with no current assignment → 200 `You are not assigned to a school for S.Y. 2026-2027. Contact the administrator.`; `/principal/teachers` shows the same page.
  - `testNoCurrentSchoolYear` → `No current school year.`
  - `testTeachersLinkGoesToOwnSchool`: `/principal/teachers` → redirect `/schools/{id}/teachers`.
  - `testSharedPageUsesPrincipalLayout` (Review Focus 4): principal on own teachers page → `.sidebar__link` texts are exactly `['Dashboard', 'Teachers']`; no "User Accounts".
  - `testTeacherCannotOpenPrincipalArea`: teacher → `/principal` 403; `testPrincipalCannotOpenAdmin`: `/admin` 403.

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement.** Layout: copy the structure of `admin/_layout.html.twig` (sidebar, topbar user box, flashes include) with principal nav; dashboard stat cards reuse `.stats`/`.stat` markup; HomeController redirects `ROLE_PRINCIPAL` to `principal_dashboard`.

- [ ] **Step 4: Run** this file and `tests/Controller/SchoolTeacherControllerTest.php` (principal test now passes), then full suite.

---

### Task 6: Admin dashboard "plans expiring" card

**Files:**
- Modify: `src/Repository/SchoolRepository.php`, `src/Controller/Admin/DashboardController.php`, `templates/admin/dashboard.html.twig`
- Test: `tests/Controller/Admin/DashboardControllerTest.php`

**Interfaces:**
- Produces: `SchoolRepository::countExpiringBy(\DateTimeImmutable $limitDate): int` — active schools with non-null `planValidUntil <= $limitDate` (covers expired too).

- [ ] **Step 1: Failing test** `testPlansExpiringCard`: active schools valid until yesterday, +10 days, +60 days, null, and an inactive one expired → `.stats--totals` (or a new row) in its own row `section.stats.stats--plans` under heading `Plans`, card `Plans expired or expiring in 30 days` = `2`, href `/admin/schools`. The totals row and its test stay unchanged.

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (today from `ClockInterface`). **Step 4: Run** file, full suite, lints → PASS.

- [ ] **Step 5: Manual check** at `http://localhost:8081`: admin Plans page; set a school Individual × 1 with expiry; add teacher via Schools → Teachers; principal sign-in → dashboard, Teachers, limit message.
