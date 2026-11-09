# Modular architecture, Phase 2e (Schools: people) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the people side of schools into the `Schools` module (principal assignment, the school's teachers, the admin's teacher-school screens, `PlanLimits`), every action through the Core bus, with nothing a user would notice changing.

**Architecture:** Same patterns as Phase 2d. `PrincipalAssigner` and `SchoolTeacherManager` become Schools Application services (names kept; the demo fixtures and legacy tests call them), and the new handlers are thin wrappers that take the school's teacher lock. `PlanLimits` becomes a Schools Domain service; learner counts and the "teacher leaves a school" rules (loads, advisory) reach it through Schools hooks implemented by legacy classes. Account work Schools needs (find by username, register a new account, set a temporary password, username taken) goes through Access's public API.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Twig, PHPUnit 13, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row `Schools`, sections 5, 6, 8, 10). Owner decisions (2026-11-07): all of `PlanLimits` moves to Schools; learner counts through a Schools hook; the section-full check takes the section's id and label instead of the `Section` entity. Deferred from the Access review: `TransactionLock` refuses the same key twice in one use case, and `acquire()` outside a use case is never released.

**Branch:** `feature/modular-architecture` (Phase 2d committed as `288e986`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 1. Locks, Access API and the Schools people domain | 2026-11-07 21:48:50 | 21:54:28 | 5m 38s |
| 2. Principal assignment | 21:54:28 | 21:57:57 | 3m 29s |
| 3. The school's teachers | 21:57:57 | 22:04:01 | 6m 04s |
| 4. Admin teacher-school screens | 22:04:01 | 22:06:32 | 2m 31s |
| 5. Baselines, docs, comments, deprecations | 22:06:32 | 22:46:41 | 40m 09s (includes the Docker outage and restart) |
| 6. Verification (full runs, review fixes) | 22:46:41 | 2026-11-08 00:02:03 | 1h 15m 22s |
| 6. Live check (headless) | 00:02:03 | 00:22:24 | 20m 21s |

## Global Constraints

- Structure only: URLs, route names, form field names (`principal_assignment_form[...]`, `teacher_form[...]`, `existing_teacher_form[...]`, `teacher_school_form[...]`), flash messages (including the `credentials` flash), error texts and where they show, activity-log lines, lock keys and access rules stay as today. Table and column names stay; `doctrine:migrations:diff` stays empty; the route table matches HEAD.
- Schools may use Core, Access Domain, Access Api, Billing Domain and Billing Api. Enrollments, sections and teaching loads reach Schools only through its hook interfaces.
- Controllers extend `BusController`; handlers refuse by throwing a Schools/Core `DomainException`; forms bind to the bus Command objects. Templates move to `src/Schools/Presentation/templates` with their relative paths kept.
- New code: `declare(strict_types=1)`, PHPStan level 9 with no baseline entries, phpcs PSR-12 (lines ≤ 120), 100% coverage of `src/Core`, `src/Access`, `src/Billing`, `src/Schools`.
- Moved tests keep their assertions. New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first) with every setup line under `// Initialize`, the existing helpers (`AdminTestCase`, `SchoolFixturesTrait`), and `createStub` unless the test sets expectations.
- Helper scripts are written with the Write tool to the scratchpad and write files with `newline='\n'`; edits with backslashes go through the file tools. Before the first code change, save the route dump of HEAD for the later comparison.
- Tests are written per task and run once in Task 6, one command at a time, in Docker. `MSYS_NO_PATHCONV=1` for `docker exec` with container paths. After a heavy run, check MySQL is still up before database steps.
- PHPStan baseline: delete `reports/phpstan/.cache`, prune entries for deleted files, regenerate; the total must not grow and no entry may name a module folder or a file not on `develop`.
- Live-browser check: ask first and confirm headless or visible. Comments short and plain; no Claude/Anthropic mention. One commit at the end after approval, through the Base hook.

## Review Focus

1. **Plan seats under concurrency.** Two requests adding a teacher at once never both pass the seat check: every teacher change takes `school-teachers-<schoolId>` (the same key as the Access reactivation guard); a busy lock shows "Another change to this school's teachers is being saved. Please try again."; a double-submitted form (unique username or assignment clash on commit) shows "This was already saved by another request. Please check the list and try again." instead of an error page. Pinned by `TeacherHandlersTest::testABusySchoolIsRefused` and `SchoolTeacherControllerTest::testDoubleSubmitShowsTheAlreadySavedMessage` (Task 3).
2. **Seat and plan refusals.** Inactive school, expired plan ("The plan of <school> expired on <M j, Y>. Contact the administrator to renew."), full plan ("The <plan> plan allows <n> teacher(s) (<used> used). Contact the administrator to upgrade."), and on the Individual plan a full section ("<section> is full (<n> learners)."). Pinned by the moved `PlanLimitsTest` (Task 1) and the moved controller tests.
3. **Bringing teachers back.** Inactive accounts, teachers already elsewhere and the seat limit are skipped with today's reasons ("<name>: account is inactive", "<name>: already assigned to <school>", "<name>: limit reached"); nothing added at all when the school is blocked. Pinned by the moved `SchoolTeacherControllerTest` and `TeacherHandlersTest::testBringBackCountsSeatsAsItAdds` (Task 3).
4. **Moving or removing a teacher.** Refused while they have loads ("<name> still has <n> load(s) this school year."), clears their advisory with the "Removed <username> as adviser of <section>" log line, and frees the old school's seat. Pinned by the moved `InUseProtectionTest`, `SectionAdvisoryCleanupTest` and `TeacherSchoolControllerTest` (Tasks 1 and 4).
5. **Usernames.** A new or edited teacher's username is trimmed and lowercased, refused when taken ("This username is already taken.", under `username`), and checked with the other field errors at once; adding an existing teacher looks the username up case-insensitively. Pinned by `UniqueTeacherUsernameValidatorTest` and the moved controller tests (Task 3).

## Rulings in this plan

- `PlanLimitException` and `AssignmentException` move to `App\Schools\Domain\Exception` with their class names and become Core `DomainException`s (codes `PLAN_LIMIT`, `ASSIGNMENT_REFUSED`); legacy code catches them by class, so it keeps working.
- `PlanLimits` (`App\Schools\Domain\Service\Plan\PlanLimits`) keeps its public methods; `assertCanAddStudent()`, `canAddStudent()` and `sectionFullReason()` take `int $sectionId, string $sectionLabel` instead of `Section` (owner). Legacy callers pass `$section->getId()` and `$section->getLabel()`.
- The double-submit message: a `UniqueConstraintViolationException` on commit comes back as a non-domain failure; the Schools teacher controllers recognise it and show today's message, instead of a Core change that would alter other modules.
- The teacher PDS download (`school_teacher_pds`) needs the Staff module, so it stays legacy in `App\Controller\SchoolTeacherPdsController` (same route) until Staff moves.
- `config/validator/User.yaml` is deleted: the teacher forms bind to commands with the same rules.
- A forged CSRF token on an unknown id gets the token refusal instead of a 404 (as in 2c/2d).

---

### Task 1: Locks, Access API and the Schools people domain

**Files:**
- Modify: `src/Core/Infrastructure/Lock/TransactionLock.php`, `src/Core/Domain/Service/Lock/TransactionLockInterface.php` (doc), `src/Core/Infrastructure/Bus/Middleware/ReleaseLocksMiddleware.php`
- Modify: `src/Access/Application/Api/AccountDirectoryInterface.php`, `src/Access/Application/Api/AccountDirectory.php`
- Move: `src/Service/PlanLimits.php` → `src/Schools/Domain/Service/Plan/PlanLimits.php`; `src/Service/{PlanLimitException,AssignmentException}.php` → `src/Schools/Domain/Exception/`; `src/Service/{PrincipalAssigner,SchoolTeacherManager,CopyResult,BringBackResult}.php` → `src/Schools/Application/Service/`; `src/Service/AccessHooks/TeacherSeatReactivationGuard.php` → `src/Schools/Infrastructure/Access/`; `src/Service/SchoolTeacherLock.php` is deleted (handlers take the lock)
- Create: `src/Schools/Domain/Service/Plan/EnrolledLearnersInterface.php`, `src/Schools/Domain/Service/Assignment/TeacherDepartureInterface.php`, `src/Service/SchoolsHooks/{CurrentEnrolledLearners,CurrentTeacherDeparture}.php` (legacy)
- Modify: every legacy file naming a moved class; the student-check callers (`LearnerEnrollment`, `TransferManager`) pass the section id and label
- Move tests: `tests/Service/PlanLimitsTest.php` → `tests/Schools/Integration/Domain/`, `tests/Service/PrincipalAssignerTest.php` → `tests/Schools/Integration/Application/` (only construction and imports change)
- Test (new): `tests/Core/Integration/Lock/TransactionLockTest.php` cases, `tests/Access/Integration/Api/AccountDirectoryTest.php`, `tests/Schools/Integration/Infrastructure/{CurrentEnrolledLearnersTest,CurrentTeacherDepartureTest}.php`, `tests/Schools/Integration/Infrastructure/TeacherSeatReactivationGuardTest.php` if Access's tests don't reach every line

**Interfaces:**
- Produces:
  - `TransactionLock`: acquiring a key it already holds returns at once; `acquire()` throws `\LogicException('Transaction locks are taken in use case handlers only.')` unless `ReleaseLocksMiddleware` has opened it (`open(): void` before the stack, `releaseAll()` after, nesting-safe).
  - `AccountDirectoryInterface` gains: `findOneByUsername(string $username): ?User` (trimmed, case-insensitive), `register(User $account): void` (saved on the next flush), `setTemporaryPassword(User $account): string` (generates, hashes, sets `mustChangePassword`, returns the plain password), `isUsernameTaken(string $username, ?int $exceptAccountId = null): bool`.
  - `EnrolledLearnersInterface`: `countForSchool(School, SchoolYear): int`, `countForSection(int $sectionId): int`.
  - `TeacherDepartureInterface`: `countLoads(User $teacher, SchoolYear): int`, `clearAdvisory(User $teacher, SchoolYear): ?string` (the cleared section's label, or null).
  - `PlanLimits(SchoolAssignmentRepositoryInterface, EnrolledLearnersInterface, SchoolClockInterface)` with today's methods and messages (see Rulings for the section signature).
  - `PrincipalAssigner` and `SchoolTeacherManager`: today's public methods, signatures and log lines, built on the Schools repository interfaces, the hooks and the Access API (no `EntityManagerInterface`, no Symfony hasher).

- [ ] **Step 1: Save the HEAD route dump** (scratchpad) before any change.
- [ ] **Step 2: Write the tests.** `TransactionLockTest`: same key twice in one use case passes; outside a use case refused; released after the use case. `AccountDirectoryTest`: find by username in any case, register waits for flush, temporary password is hashed and must be changed, username taken except for the given account. Hook tests against real enrollments, sections, loads (moved from what `PlanLimitsTest`/`SectionAdvisoryCleanupTest` already build).
- [ ] **Step 3: Move and rewrite** with a scratchpad script (FQCN map as in 2d), then the constructor and signature changes above; update the section callers by hand.
- [ ] **Step 4: Setup checks:** `cache:clear`, `lint:container`, `doctrine:schema:validate`, `doctrine:migrations:diff` empty.

### Task 2: Principal assignment

**Files:**
- Create: `src/Schools/Application/UseCase/Command/{AssignPrincipalCommand,RemovePrincipalCommand,CopyPrincipalsCommand}.php`, `src/Schools/Application/Query/Command/GetPrincipalAssignmentCommand.php`, their handlers, `src/Schools/Presentation/Controller/SchoolPrincipalController.php`, `src/Schools/Presentation/Form/PrincipalAssignmentFormType.php` (moved; class name kept)
- Move template: `templates/admin/schools/assign_principal.html.twig` → `src/Schools/Presentation/templates/admin/schools/`
- Delete: `src/Controller/Admin/SchoolPrincipalController.php`, `src/Form/PrincipalAssignmentFormType.php`
- Move tests: `tests/Controller/Admin/SchoolPrincipalTest.php` → `tests/Schools/Functional/`
- Test (new): `tests/Schools/Unit/Application/PrincipalHandlersTest.php`, new controller cases

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `GetPrincipalAssignmentCommand(int $schoolId, ?int $schoolYearId)` → `['school' => SchoolData, 'schoolYear' => SchoolYearData, 'current' => ?array{id: int, fullName: string}, 'principals' => list<array{id: int, fullName: string}>]` (assignable principals plus the current one); unknown school → 404; unknown year → 404 "School year not found."; no year at all → 404 "Create a school year first.".
  - `AssignPrincipalCommand(int $schoolId, ?int $schoolYearId)` with `?int $principalId` (`NotNull(message: 'Select a principal.')`) → `AssignmentException` refusals as today, flash "<name> is now the principal of <school> for <year>." (refusals as an error flash, both redirect to the list with `?sy=`).
  - `RemovePrincipalCommand`, `CopyPrincipalsCommand` → today's flashes ("Copied <n> principal assignment(s).", "Skipped: ..." warning).
  - The form's `principal` field becomes a `ChoiceType` of account ids labelled by full name (`property_path: 'principalId'`, same placeholder), so the HTML matches today's `EntityType`.
- [ ] **Step 1: Move the test** (assertions unchanged) and add `testUnknownSchoolIs404`, `testUnknownSchoolYearIs404`; `PrincipalHandlersTest` for every handler's success and refusal paths.
- [ ] **Step 2: Implement** commands, handlers, controller, form, template.
- [ ] **Step 3: Setup checks:** `lint:twig`, route table identical to HEAD.

### Task 3: The school's teachers

**Files:**
- Create: `src/Schools/Application/UseCase/Command/{CreateTeacherCommand,AddExistingTeacherCommand,UpdateTeacherCommand,ToggleTeacherActiveCommand,ResetTeacherPasswordCommand,BringBackTeachersCommand}.php`, `src/Schools/Application/Query/Command/{ListSchoolTeachersCommand,GetSchoolTeacherCommand,GetTeacherSeatCommand}.php`, their handlers, `src/Schools/Application/Transformer/TeacherTransformer.php`, `src/Schools/Presentation/Controller/SchoolTeacherController.php`, `src/Schools/Presentation/Form/{TeacherFormType,ExistingTeacherFormType}.php` (moved), `src/Schools/Presentation/Validation/{UniqueTeacherUsername,UniqueTeacherUsernameValidator}.php`, `src/Controller/SchoolTeacherPdsController.php` (legacy, the PDS action only)
- Move templates: `templates/school_teachers/{index,form,add_existing}.html.twig` → `src/Schools/Presentation/templates/school_teachers/`
- Delete: `src/Controller/SchoolTeacherController.php`, `src/Form/{TeacherFormType,ExistingTeacherFormType}.php`, `config/validator/User.yaml`
- Move tests: `tests/Controller/SchoolTeacherControllerTest.php` → `tests/Schools/Functional/`
- Test (new): `tests/Schools/Unit/Application/TeacherHandlersTest.php`, `tests/Schools/Unit/Presentation/UniqueTeacherUsernameValidatorTest.php`, new controller cases

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - Every write handler first takes `school-teachers-<schoolId>` with the busy message above, then calls `SchoolTeacherManager`.
  - `CreateTeacherCommand(int $schoolId)` with `fullName` (`NotBlank`, `Length(max: 120)`) and `username` (`NotBlank`, `Length(min: 3, max: 50)`, `Regex('/^[a-zA-Z0-9._-]+$/', 'Use only letters, numbers, dots, dashes and underscores.')`), class constraint `UniqueTeacherUsername`; returns `['fullName', 'username', 'password']` for the `credentials` flash ("Teacher created"). `UpdateTeacherCommand(int $schoolId, int $teacherId)` with the same fields (own username allowed) → log "Updated teacher <username>", flash "Saved changes to <name>.".
  - `AddExistingTeacherCommand(int $schoolId)` with `username` (`NotBlank(message: "Enter the teacher's username.")`) → refusals under `username`; flash "<name> was added to the teachers of <school>.".
  - `ToggleTeacherActiveCommand`, `ResetTeacherPasswordCommand` ("Password reset" credentials), `BringBackTeachersCommand` ("Brought back <n> teacher(s).", "Not brought back: ..." warning) with today's flashes; an unknown teacher of the school → 404 "This teacher does not belong to the school this school year.".
  - `ListSchoolTeachersCommand(int $schoolId)` → `['school', 'schoolYear', 'teachers' => list<TeacherData>, 'used' => int, 'addRefusal' => ?string, 'bringBackFrom' => ?SchoolYearData]`; `GetTeacherSeatCommand(int $schoolId)` → `['school', 'refusal' => ?string]` for the new/add-existing pages (a refusal redirects to the list with an error flash, as today); `GetSchoolTeacherCommand(int $schoolId, int $teacherId)` → `TeacherData` or 404.
  - `TeacherTransformer::toArray(User): array{id: int, fullName: string, username: string, active: bool}`; templates read the same keys.
- [ ] **Step 1: Move the test** and add `testDoubleSubmitShowsTheAlreadySavedMessage` (a stale form re-posted after the first save) and `testUnknownTeacherIs404`; `TeacherHandlersTest` (`testABusySchoolIsRefused` with the lock held, `testBringBackCountsSeatsAsItAdds`, each refusal); validator test as in 2d.
- [ ] **Step 2: Implement** commands, handlers, transformer, constraint, forms, controllers, templates; delete `User.yaml`.
- [ ] **Step 3: Setup checks:** `lint:container`, `lint:twig`, route table identical to HEAD.

### Task 4: Admin teacher-school screens

**Files:**
- Create: `src/Schools/Application/UseCase/Command/{AssignTeacherSchoolCommand,RemoveTeacherSchoolCommand}.php`, `src/Schools/Application/Query/Command/GetTeacherSchoolCommand.php`, their handlers, `src/Schools/Presentation/Controller/TeacherSchoolController.php`, `src/Schools/Presentation/Form/TeacherSchoolFormType.php` (moved; `school` becomes a `ChoiceType` of active school ids labelled "<schoolId> · <name>", same placeholder and "Choose a school." message)
- Move template: `templates/admin/accounts/school.html.twig` → `src/Schools/Presentation/templates/admin/accounts/`
- Delete: `src/Controller/Admin/TeacherSchoolController.php`, `src/Form/TeacherSchoolFormType.php`
- Move tests: `tests/Controller/Admin/TeacherSchoolControllerTest.php` → `tests/Schools/Functional/`
- Test (new): `tests/Schools/Unit/Application/TeacherSchoolHandlersTest.php`, new controller cases

**Interfaces:**
- Consumes: Tasks 1 and 3.
- Produces: `GetTeacherSchoolCommand(int $accountId)` → `['account' => TeacherData, 'schoolYear' => SchoolYearData, 'current' => ?SchoolData, 'schools' => list<array{id: int, label: string}>]`; a non-teacher → 404 "Only teacher accounts are assigned to a school here."; no current year → 404 "No current school year.". `AssignTeacherSchoolCommand(int $accountId)` with `?int $schoolId` takes the target school's lock; refusals under `school`; flash "<name> teaches at <school> for <year>.". `RemoveTeacherSchoolCommand(int $accountId)` → flash "<name> no longer has a school for <year>." or the refusal as an error flash.
- [ ] **Step 1: Move the test** and add `testNonTeacherIs404`; `TeacherSchoolHandlersTest` for success and refusal paths.
- [ ] **Step 2: Implement.**
- [ ] **Step 3: Setup checks:** `lint:twig`, route table identical to HEAD.

### Task 5: Baselines, docs, comments, deprecations

- [ ] **Step 1:** phpcbf, then phpcs clean. PHPStan on the module folders with no errors, then baseline regeneration under the Global Constraints rules; deptrac 0 violations.
- [ ] **Step 2: Docs.** `CLAUDE.md`: Schools now also holds principal assignment, the school's teachers, the admin teacher-school screens and `PlanLimits`; the new hooks (`EnrolledLearnersInterface`, `TeacherDepartureInterface`); Access's API methods; `TransactionLockInterface` only inside use case handlers, same key twice allowed; `config/validator/User.yaml` gone.
- [ ] **Step 3: Comment sweep and deprecation check** (`debug:container --deprecations`) over everything touched.

### Task 6: Verification

- [ ] **Step 1: Scoped run** of `tests/Schools tests/Access tests/Core tests/Controller tests/Service tests/Security tests/DataFixtures` with `--display-phpunit-notices`; fix, re-run only failures.
- [ ] **Step 2: Coverage on module tests alone** → nothing uncovered.
- [ ] **Step 3: Full runs on the final code, one at a time:** `make test all NO_PARALLEL=1`, `make test-feature`, `make regression-test`; schema valid, diff empty; route table identical to HEAD.
- [ ] **Step 4: Fresh whole-change review** (most capable model, read-only); fix Critical/Important with a failing test first; full runs again.
- [ ] **Step 5: Ask the owner before the live-browser check, and whether headless or visible.** If headless: HEAD vs branch on the same dev data (data reset between sides): the teacher list, new/add-existing/edit pages, principal assignment page, admin teacher-school page; flows: create a teacher (credentials flash), duplicate username, add an existing teacher, deactivate/reactivate, reset password, assign and remove a principal, assign a teacher to a school; then restore the dev data.
- [ ] **Step 6: Report** and propose the commit; commit only after approval:

```
Move principals, school teachers and plan limits into the Schools module

Move principal assignment, the school's teacher screens, the admin's
teacher-school screens and the plan limits into the Schools module, with
every action through the bus. Learner counts and a teacher's loads and
advisory reach it through hooks, accounts through an Access API. Pages,
messages and URLs are unchanged.
```
