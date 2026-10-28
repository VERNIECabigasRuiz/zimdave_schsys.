# Learner Enrollment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admins, principals and advisers enroll learners (LRN + student account) into class sections one at a time, with plan limits, and learners move between schools through a transfer request the old school approves.

**Architecture:** Three new entities (`Learner`, `Enrollment`, `TransferRequest`) and the `TransferStatus` enum. Writes go through `LearnerEnrollment` and `TransferManager`, under `LearnerLock` (`symfony/lock`), checking `PlanLimits`. `LearnerVoter` holds the access table. Pages follow the Subjects pattern: `AbstractLearnerController` + a `LearnerScope`, with a school subclass (`/schools/{id}/learners`, admin and principal) and an adviser subclass (`/teacher/class`).

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3 (MariaDB 10.4), Twig, Symfony Form/Validator, symfony/lock, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-28-learner-enrollment-design.md`

## Global Constraints

- Project root `C:\xampp\htdocs\zimdave_schsys`, branch `feature/learners`. Apache and MySQL must be running (start them with working directory `C:\xampp`).
- Run tests with `php bin/phpunit` (filter with `--filter`). One phpunit process at a time; tests share `zimdave_schsys_test`. Avast may suspend `php.exe`: run phpunit through PowerShell `Start-Process -PassThru` + `Wait-Process -Timeout`, retry on timeout, never pipe its output in the Bash tool.
- TDD: every step that adds behavior starts with a failing test, run and seen failing. Commit after each task with the given message, ending with the session's `Co-Authored-By` line.
- Schema changes only via `php bin/console make:migration`, then `doctrine:migrations:migrate --no-interaction` for dev **and** `--env=test`.
- Everything works on the current school year (`SchoolYearRepository::findCurrent()`); ids from another school or year → 404.
- Every write is refused while `PlanLimits::blockedReason($school)` is not null (`PlanLimitException`).
- "Plan without a principal" = `School::getPlan()?->includesPrincipal() === false` (as `TeacherController::ownsSections`).
- All user-facing copy and activity-log text comes **verbatim** from the spec's **Copy** section; `<k> learners` / `<k> pending transfers` use the singular when k = 1.
- Follow existing patterns: `SchoolTeacherLock` (lock + `UniqueConstraintViolationException`), `SchoolTeacherManager::create` (temporary password, `credentials` flash in `templates/admin/_flashes.html.twig`), `SectionManager::delete` (`InUseException`), `AbstractSubjectController` + `SubjectScope` + `TeacherSubjectController`, tests on `AdminTestCase` + `SchoolFixturesTrait` + `ClockSensitiveTrait`.
- Out of scope (spec): whole-section re-enroll / bring back (never), CSV import, statuses, contacts, deleting learners.

## Review Focus

1. **Two people enrolling the last free place** at once: one wins, the other gets the limit message or `Another change is being saved. Please try again.`, never both and never a 500. Task 4 `testLastPlaceIsTakenOnce` (holds the school lock) and `testUniqueViolationBecomesFriendlyMessage`.
2. **A transfer whose new school or section filled up** before approval is refused and stays pending. Task 6 `testApproveRechecksLimits`.
3. **An adviser posting a section id that isn't theirs** (edited form) is refused, not enrolled. Task 9 `testAdviserCannotPostAnotherSection`.
4. **A teacher who stops being an adviser** gets 403 on their next request. Task 9 `testFormerAdviserIsForbidden`.
5. **Deleting a section** with learners or pending transfers into it is refused. Task 7 `testSectionWithLearnersCannotBeDeleted`, `testSectionWithPendingTransfersCannotBeDeleted`.

---

### Task 1: Entities, repositories and migration

**Files:**
- Create: `src/Entity/Learner.php`, `src/Entity/Enrollment.php`, `src/Entity/TransferRequest.php`, `src/Entity/TransferStatus.php`
- Create: `src/Repository/LearnerRepository.php`, `src/Repository/EnrollmentRepository.php`, `src/Repository/TransferRequestRepository.php`
- Create: `migrations/Version<timestamp>.php` (generated)
- Test: `tests/Entity/LearnerTest.php`, `tests/Repository/EnrollmentRepositoryTest.php`

**Interfaces:**
- Produces:
  - `Learner(User $user)`; getters/setters `lrn`, `lastName`, `firstName`, `middleName`, `extension`, `sex` (`SexAtBirth`), `birthDate`; `getUser(): User`; `getFullName(): string`. Every name setter also calls `$user->setFullName($this->getFullName())`; `setLrn()` also sets `$user->setUsername()`.
  - `Enrollment(Learner, SchoolYear, Section, User $enrolledBy)` (school taken from the section); `getSchool()`, `getSection()`, `moveTo(Section)` (sets section and school).
  - `TransferRequest(Enrollment $enrollment, Section $toSection, User $requestedBy)` (copies learner, fromSchool, fromSection); `getStatus()`, `decide(TransferStatus, User $by, \DateTimeImmutable $at)`, `isPending()`.
  - `enum TransferStatus: string { Pending='pending'; Approved='approved'; Declined='declined'; Cancelled='cancelled' }`.
  - `LearnerRepository::findOneByLrn(string $lrn): ?Learner`
  - `EnrollmentRepository`: `findCurrent(Learner, SchoolYear): ?Enrollment`, `countForSchool(School, SchoolYear): int`, `countForSection(Section): int`, `countBySection(School, SchoolYear): array<int, int>` (section id → count), `findClassList(Section): list<Enrollment>`, `search(School, SchoolYear, ?string $query, ?Section $section): list<Enrollment>`, `findHistory(Learner): list<Enrollment>` (newest year first).
  - `TransferRequestRepository`: `findPending(Learner): ?TransferRequest`, `findIncoming(School, SchoolYear): list`, `findOutgoing(School, SchoolYear): list`, `countPendingOutgoing(School, SchoolYear, ?Section $fromSection = null): int`, `countPendingInto(Section): int`, `findForLearner(Learner): list`.

- [ ] **Step 1: Write failing tests.** `LearnerTest` (validator from the container):
  - `testFullNameFormat`: Dela Cruz / Juan / Santos / Jr. → `getFullName()` and `getUser()->getFullName()` are `Dela Cruz, Juan Santos Jr.`; with no middle name or extension → `Dela Cruz, Juan`.
  - `testLrnSetsUsername`: `setLrn('123456789012')` → user's username `123456789012`.
  - `testLrnMustBe12Digits`: `'12345678901'`, `'12345678901A'` → violation `The LRN must be exactly 12 digits.`
  - `testLrnUnique`: second learner with a saved LRN → `UniqueEntity` violation `LRN <lrn> belongs to <name>.`
  - `testBirthDateBeforeToday`: today → `Birthdate must be before today.`
  - `EnrollmentRepositoryTest::testClassListOrder`: learners (F Abad, M Cruz, M Abad, F Cruz) → class list order M Abad, M Cruz, F Abad, F Cruz.
  - `EnrollmentRepositoryTest::testOneEnrollmentPerYear`: a second `Enrollment` for the same learner and year → `UniqueConstraintViolationException` on flush.
- [ ] **Step 2: Run** `php bin/phpunit --filter "LearnerTest|EnrollmentRepositoryTest"`. Expected: FAIL (classes missing).
- [ ] **Step 3: Implement the entities and repositories** with the fields and rules of the spec's Data section. `Enrollment` unique constraint `uniq_enrollment_learner_year` on `(learner_id, school_year_id)`; `Learner.user` OneToOne unique, `Learner.lrn` unique column. Class-list order: `sex` (male first), `lastName`, `firstName`.
- [ ] **Step 4: Generate and run the migration** for dev and test (see Global Constraints); `php bin/console doctrine:schema:validate` → in sync.
- [ ] **Step 5: Run** the tests from Step 2. Expected: PASS.
- [ ] **Step 6: Commit** `feat: learner, enrollment and transfer request entities`.

### Task 2: Student limits in PlanLimits

**Files:**
- Modify: `src/Service/PlanLimits.php`, `src/Controller/Principal/DashboardController.php`, `templates/principal/dashboard.html.twig`
- Test: `tests/Service/PlanLimitsTest.php`, `tests/Controller/Principal/*Dashboard*Test.php` (the existing principal dashboard test)

**Interfaces:**
- Consumes: `EnrollmentRepository::countForSchool`, `countForSection` (Task 1).
- Produces: `PlanLimits::countStudents(School, SchoolYear): int`, `PlanLimits::canAddStudent(School, SchoolYear, Section $section): ?string`, `PlanLimits::assertCanAddStudent(School, SchoolYear, Section $section): void` (throws `PlanLimitException`).

- [ ] **Step 1: Write failing tests** in `PlanLimitsTest`:
  - `testStudentLimit`: plan 2 students/seat, 1 seat, 2 enrolled → `The Basic plan allows 2 student(s) (2 used). Contact the administrator to upgrade.`; 1 enrolled → null.
  - `testSectionFullOnPlanWithoutPrincipal`: `includesPrincipal = false`, studentsPerSeat 2, 2 seats, section with 2 → `<section> is full (2 learners).`; same counts on a plan with a principal → null.
  - `testBlockedSchoolRefusesStudents`: inactive school → `This school is inactive.`
  - Dashboard: the Students card shows `1 / <limit>` with one learner enrolled this year (and the old comment is gone).
- [ ] **Step 2: Run** them. Expected: FAIL.
- [ ] **Step 3: Implement** the three methods (blocked reason first, then school limit, then section limit), and pass `students` to the principal dashboard.
- [ ] **Step 4: Run** them. Expected: PASS.
- [ ] **Step 5: Commit** `feat: enforce the plan's student limit`.

### Task 3: LearnerVoter

**Files:**
- Create: `src/Security/Voter/LearnerVoter.php`
- Test: `tests/Security/LearnerVoterTest.php`

**Interfaces:**
- Consumes: `SchoolVoter` rules (admin; principal of school this year via `SchoolAssignmentRepository::findSchoolOf`), `EnrollmentRepository::findCurrent`, `SchoolYearRepository::findCurrent`.
- Produces constants and subjects:
  - `ENROLL = 'LEARNER_ENROLL'` on `Section`: admin; principal of the section's school; the section's adviser. Section must be in the current year.
  - `EDIT = 'LEARNER_EDIT'`, `RESET_PASSWORD = 'LEARNER_RESET_PASSWORD'`, `UNENROLL = 'LEARNER_UNENROLL'` on `Learner`: admin; principal of their current school; adviser of their current section.
  - `CHANGE_LRN = 'LEARNER_CHANGE_LRN'`, `MOVE = 'LEARNER_MOVE'` on `Learner`: admin; principal of their current school.
  - `REQUEST_TRANSFER = 'LEARNER_REQUEST_TRANSFER'` on `School` (the receiving school): its principal only.
  - `DECIDE_TRANSFER = 'TRANSFER_DECIDE'` on `TransferRequest`: admin; principal of `fromSchool`; adviser of `fromSection` only when `fromSchool`'s plan has no principal. Pending only.
  - `CANCEL_TRANSFER = 'TRANSFER_CANCEL'` on `TransferRequest`: admin; principal of the receiving school. Pending only.
  - `VIEW_CLASS = 'LEARNER_VIEW_CLASS'` on `Section`: same as `ENROLL`.
  - A learner with no current enrollment: `EDIT`/`RESET_PASSWORD` admin only (principals reach them again by enrolling).

- [ ] **Step 1: Write failing tests**: one test method per row of the spec's access table, each asserting every role column (admin, principal of the school, principal of another school, adviser of the section, adviser of another section, teacher with no advisory, student). Plus `testFormerAdviserLosesAccess` (adviser cleared → denied) and `testDecidedRequestCannotBeDecidedAgain`.
- [ ] **Step 2: Run** `php bin/phpunit --filter LearnerVoterTest`. Expected: FAIL.
- [ ] **Step 3: Implement** the voter.
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: learner voter`.

### Task 4: LearnerEnrollment service

**Files:**
- Create: `src/Service/LearnerLock.php`, `src/Service/EnrollmentException.php`, `src/Service/LearnerEnrollment.php`
- Test: `tests/Service/LearnerEnrollmentTest.php`

**Interfaces:**
- Consumes: `PlanLimits::assertCanAddStudent` (Task 2), repositories (Task 1), `TemporaryPasswordGenerator`, `UserPasswordHasherInterface`, `ActivityLogger`.
- Produces:
  - `EnrollmentException extends \RuntimeException` (refusals that are not plan limits).
  - `LearnerLock::forSchool(School, callable): mixed` (key `learners-school-<id>`), `LearnerLock::forLearner(Learner, callable): mixed` (key `learner-<id>`). Busy lock or `UniqueConstraintViolationException` → `EnrollmentException('Another change is being saved. Please try again.')`.
  - `LearnerEnrollment::enrollNew(Learner $learner, Section $section, User $by): string` (creates the `ROLE_STUDENT` account with temporary password, `mustChangePassword = true`; returns the password).
  - `LearnerEnrollment::enrollExisting(Learner $learner, Section $section, User $by): void` (refuses when the learner is enrolled this year: at the same school → `<name> is already enrolled in <section>.`; at another school → `<name> is enrolled at <school>.`. The enroll page shows the role-specific wording from the spec before this is ever called).
  - `update(Learner): void`, `changeLrn(Learner, string $lrn): void` (refuses a username taken by another account), `move(Enrollment, Section $to): void` (same school only), `unenroll(Enrollment): void`, `resetPassword(Learner): string`, `transferDirectly(Enrollment, Section $to, User $by): void` (admin).
  - Every write: `PlanLimits` check where it adds a learner to a school or section, school lock, activity log text from the spec, flush. Moving, unenrolling and transferring a learner with a pending request → `<name> has a transfer request waiting.`

- [ ] **Step 1: Write failing tests**:
  - `testEnrollNewCreatesStudentAccount`: user role `ROLE_STUDENT`, username = LRN, `mustChangePassword`, password verifies, enrollment in section, log `Enrolled <lrn> in <section>`.
  - `testEnrollExistingNextYear`: learner enrolled last year → enrolled this year, history has 2 rows.
  - `testAlreadyEnrolledHere` / `testEnrolledElsewhereRefused`: messages as above.
  - `testSchoolLimit` / `testSectionLimitOnPlanWithoutPrincipal`: limit messages from Task 2.
  - `testLastPlaceIsTakenOnce` (Review Focus 1): acquire `learners-school-<id>` from the test's `LockFactory`, then `enrollNew` → `Another change is being saved. Please try again.`, nothing saved.
  - `testUniqueViolationBecomesFriendlyMessage`: insert a clashing enrollment row via DBAL, then `enrollExisting` → same message, no 500.
  - `testChangeLrnUpdatesUsername`, `testChangeLrnRefusesTakenUsername`, `testMoveWithinSchool`, `testMoveToOtherSchoolRefused`, `testUnenrollKeepsLearnerAndAccount`, `testPendingTransferBlocksMoveAndUnenroll`, `testTransferDirectly` (log `Transferred <lrn> from <school> to <school>`, limits checked), `testBlockedPlanRefusesEveryWrite`.
- [ ] **Step 2: Run** `php bin/phpunit --filter LearnerEnrollmentTest`. Expected: FAIL.
- [ ] **Step 3: Implement** `EnrollmentException`, `LearnerLock` (as `SchoolTeacherLock`), `LearnerEnrollment`.
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: learner enrollment service`.

### Task 5: LearnerLookup

**Files:**
- Create: `src/Service/LearnerLookup.php`, `src/Service/LookupResult.php`, `src/Service/LookupKind.php`
- Test: `tests/Service/LearnerLookupTest.php`

**Interfaces:**
- Produces: `enum LookupKind { New; NotEnrolled; Here; Elsewhere }`; `final readonly class LookupResult(LookupKind $kind, ?Learner $learner, ?Enrollment $enrollment)`; `LearnerLookup::find(string $lrn, School $school, SchoolYear $schoolYear): LookupResult`.

- [ ] **Step 1: Write failing tests**: one per kind (`testNewLrn`, `testNotEnrolledThisYear`, `testEnrolledHere`, `testEnrolledElsewhere`), plus `testLrnIsTrimmed` (`' 123456789012 '` finds the learner).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: learner lookup by LRN`.

### Task 6: TransferManager

**Files:**
- Create: `src/Service/TransferManager.php`
- Test: `tests/Service/TransferManagerTest.php`

**Interfaces:**
- Consumes: `LearnerLock`, `PlanLimits::assertCanAddStudent`, `TransferRequestRepository`, `ActivityLogger`, `ClockInterface`.
- Produces: `request(Enrollment $from, Section $to, User $by): TransferRequest`, `approve(TransferRequest, User $by): void`, `decline(TransferRequest, User $by): void`, `cancel(TransferRequest, User $by): void`. All refuse a request that is not pending (`EnrollmentException`).

- [ ] **Step 1: Write failing tests**:
  - `testRequestLeavesLearnerInPlace`: pending, enrollment unchanged, log `Requested transfer of <lrn> from <school>`.
  - `testSecondPendingRequestRefused`: `<name> has a transfer request waiting.`
  - `testRequestToSameSchoolRefused`.
  - `testApproveMovesEnrollment`: school and section changed, status approved, decidedBy/At set, log `Approved transfer of <lrn> to <school>`.
  - `testApproveRechecksLimits` (Review Focus 2): fill the receiving school to its limit after the request → approve refused with the limit message, request still pending, enrollment unchanged.
  - `testDecline` / `testCancel`: status and log text from the spec; enrollment unchanged.
  - `testRequestBlockedPlan`: receiving school blocked → refused.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement**: `approve` takes `forLearner`, then `forSchool(receiving school)`.
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: transfer requests`.

### Task 7: Protect sections and learner accounts

**Files:**
- Modify: `src/Service/SectionManager.php`, `src/Controller/Admin/UserAccountController.php`, `src/Form/UserAccountFormType.php`
- Test: `tests/Service/InUseProtectionTest.php`, `tests/Controller/Admin/*Account*Test.php` (the existing accounts test)

- [ ] **Step 1: Write failing tests**:
  - `testSectionWithLearnersCannotBeDeleted`: 1 learner → `InUseException` `<section> has 1 learner. Move or unenroll them first.`
  - `testSectionWithPendingTransfersCannotBeDeleted`: `<section> has 1 pending transfer.`
  - Accounts: editing a learner's account and changing its role → form error `This account belongs to learner <LRN>.`, role unchanged.
  - Accounts: the new-account role choice no longer offers Student (students are created by enrolling, so no account is left without a learner).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement** in `SectionManager::delete` (before the timetable check) and next to `guardPrincipalRoleChange`.
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: protect sections with learners and learner accounts`.

### Task 8: School learner pages (admin, principal)

**Files:**
- Create: `src/Controller/LearnerScope.php`, `src/Controller/AbstractLearnerController.php`, `src/Controller/SchoolLearnerController.php`, `src/Form/LearnerFormType.php`, `src/Form/EnrollSectionFormType.php`
- Create: `templates/learners/index.html.twig`, `enroll.html.twig`, `show.html.twig`, `form.html.twig`, `class_list.html.twig`
- Modify: `src/Controller/Principal/DashboardController.php` (`principal_learners` redirect), `templates/principal/_layout.html.twig` (Learners nav), `templates/school_sections/index.html.twig` + `SchoolSectionController::index` (Enrolled column, `countBySection`), `templates/admin/schools/index.html.twig` (Learners button)
- Test: `tests/Controller/SchoolLearnerControllerTest.php`

**Interfaces:**
- Consumes: Tasks 3–6.
- Produces:
  - `final readonly class LearnerScope(School $school, SchoolYear $schoolYear, ?Section $fixedSection, string $routes, array $routeParams, string $layout)` — `fixedSection` set for an adviser (no section picker).
  - `AbstractLearnerController` with the shared actions; routes (prefix `school_learners`, `#[IsGranted(SchoolVoter::MANAGE, 'school')]` on the class, per-learner `LearnerVoter` checks in actions):
    - `GET /schools/{id}/learners` `school_learners` (search `q`, `section` via `#[MapQueryParameter]`)
    - `GET|POST /schools/{id}/learners/enroll` `school_learners_enroll` (step 1 `lrn` query parameter, then the form for the `LookupKind`)
    - `GET /schools/{id}/learners/{learner}` `school_learners_show`
    - `GET|POST /schools/{id}/learners/{learner}/edit` `school_learners_edit` (LRN field only with `CHANGE_LRN`)
    - `POST /schools/{id}/learners/{learner}/move|unenroll|reset-password` `school_learners_move|_unenroll|_reset_password` (CSRF `learner-<id>`)
    - `GET /schools/{id}/sections/{section}/learners` `school_learners_class`
  - `principal_learners` → redirect to `school_learners`.

- [ ] **Step 1: Write failing tests** (principal logged in, `ClockSensitiveTrait`):
  - `testEnrollNewLearner`: LRN step → form → POST → redirect, `credentials` flash with username = LRN, learner in the class list.
  - `testEnrollReturningLearner`: last year's learner → read-only details + section picker → enrolled.
  - `testAlreadyEnrolledHereShowsLink`, `testEnrolledElsewhereOffersTransfer` (a `Request transfer` button is shown; the POST itself is Task 10).
  - `testClassListOrderAndCount`: boys first, header count.
  - `testSearchByLrnAndName`, `testFilterBySection`.
  - `testEditAndChangeLrn`, `testMoveUnenrollResetPassword` (each POST with CSRF; reset shows `credentials`).
  - `testSectionsPageShowsEnrolledColumn`, `testPrincipalNavHasLearners`.
  - `testOtherSchoolIs403`, `testLearnerOfOtherSchoolIs404`, `testSectionOfOtherSchoolIs404`.
  - `testLimitMessageShown` (plan full → message, nothing saved).
  - Admin: `testAdminEnrollsIntoAnySchool`.
- [ ] **Step 2: Run** `php bin/phpunit --filter SchoolLearnerControllerTest`. Expected: FAIL.
- [ ] **Step 3: Implement** controllers, forms (Symfony Form + the entity's validation), templates (extend `layout` like `school_subjects/index.html.twig`), nav and links.
- [ ] **Step 4: Run** the test and `php bin/console lint:twig templates/`. Expected: PASS / clean.
- [ ] **Step 5: Commit** `feat: school learner pages`.

### Task 9: Adviser's class pages

**Files:**
- Create: `src/Controller/TeacherClassController.php`
- Modify: `templates/teacher/_layout.html.twig` (My class nav, only with an advisory this year)
- Test: `tests/Controller/TeacherClassControllerTest.php`

**Interfaces:**
- Consumes: `AbstractLearnerController`, `LearnerScope` (Task 8) with `fixedSection` = `SectionRepository::findAdvisory($user, $year)`; layout `teacher/_layout.html.twig`.
- Produces: routes prefix `teacher_class` under `/teacher/class`: `teacher_class` (class list), `teacher_class_enroll`, `teacher_class_show`, `teacher_class_edit`, `teacher_class_unenroll`, `teacher_class_reset_password`. No advisory → 403.

- [ ] **Step 1: Write failing tests**:
  - `testAdviserEnrollsIntoOwnSection` (no section picker; credentials shown).
  - `testAdviserCannotPostAnotherSection` (Review Focus 3): tamper the form with another section id → refused, nothing saved.
  - `testEnrolledElsewhereRefusedForAdviser`: `<name> is enrolled at <school>. Ask your principal to request a transfer.`; on a plan without a principal: `… Ask the administrator to transfer them.`
  - `testTeacherWithoutAdvisoryIs403` and no `My class` link on the dashboard.
  - `testFormerAdviserIsForbidden` (Review Focus 4): clear the adviser, next GET → 403.
  - `testAdviserCannotMoveOrChangeLrn`: no Move button, no LRN field; POST to the move route of the school controller → 403.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: adviser's class pages`.

### Task 10: Transfer pages and dashboard cards

**Files:**
- Create: `src/Controller/SchoolTransferController.php`, `templates/transfers/index.html.twig`, `templates/transfers/_waiting_card.html.twig`
- Modify: `src/Controller/AbstractLearnerController.php` (Request transfer POST on the enroll page), `src/Controller/Principal/DashboardController.php` + `templates/principal/dashboard.html.twig`, `src/Controller/TeacherController.php` + `templates/teacher/dashboard.html.twig`, `templates/learners/show.html.twig` (requests on the learner page)
- Test: `tests/Controller/SchoolTransferControllerTest.php`

**Interfaces:**
- Consumes: `TransferManager` (Task 6), `LearnerVoter::REQUEST_TRANSFER`, `DECIDE_TRANSFER`, `CANCEL_TRANSFER`.
- Produces routes: `GET /schools/{id}/transfers` `school_transfers` (tabs `incoming` / `outgoing`); `POST /schools/{id}/transfers/request` `school_transfers_request`; `POST /schools/{id}/transfers/{transfer}/approve|decline|cancel` `school_transfers_approve|_decline|_cancel` (CSRF `transfer-<id>`). Access to the page: admin, principal, or the adviser on a plan without a principal (outgoing for their section only).

- [ ] **Step 1: Write failing tests**:
  - `testPrincipalRequestsTransfer`: from the enroll page → request pending, incoming tab shows `Waiting for <school>`.
  - `testOldPrincipalApproves`: dashboard shows `Transfer requests waiting: 1`; approve → learner in the new section.
  - `testDecline`, `testCancelByReceivingPrincipal`, `testReceivingPrincipalCannotApprove` (403).
  - `testAdviserApprovesOnPlanWithoutPrincipal`: request shows on the adviser's dashboard; approve works; an adviser on a plan with a principal gets 403.
  - `testApproveRefusedWhenFull`: limit message shown, still pending.
  - `testTeacherCannotRequest` (403).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: transfer request pages`.

### Task 11: Admin learner search and direct transfer

**Files:**
- Create: `src/Controller/Admin/LearnerController.php`, `templates/admin/learners/index.html.twig`
- Modify: `templates/admin/_layout.html.twig` (Learners nav), `templates/learners/show.html.twig` (Transfer form for admins)
- Test: `tests/Controller/Admin/LearnerControllerTest.php`

**Interfaces:**
- Produces: `GET /admin/learners` `admin_learners` (LRN search → learner, current school and section, link to `school_learners_show`); `POST /schools/{id}/learners/{learner}/transfer` `school_learners_transfer` (admin only, `LearnerEnrollment::transferDirectly`).

- [ ] **Step 1: Write failing tests**: `testSearchByLrnAcrossSchools`, `testAdminTransfersDirectly` (no request created, log text from the spec), `testPrincipalCannotOpenAdminSearch` (403), `testPrincipalCannotPostDirectTransfer` (403).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: admin learner search and transfer`.

### Task 12: Student dashboard

**Files:**
- Create: `src/Controller/StudentController.php`, `templates/student/_layout.html.twig`, `templates/student/dashboard.html.twig`
- Modify: `src/Controller/HomeController.php` (send `ROLE_STUDENT` to `student_dashboard`)
- Test: `tests/Controller/StudentDashboardTest.php`

**Interfaces:**
- Consumes: `EnrollmentRepository::findCurrent`, `TimetableQuery::termsFor(Section)` and `sectionState(Section, int $term)` (read only; first term of `termsFor`, or `?term=` among them).
- Produces: `GET /student` `student_dashboard`, `#[IsGranted(User::ROLE_STUDENT)]`.

- [ ] **Step 1: Write failing tests**: `testHomeRedirectsStudent`, `testShowsSchoolSectionAdviserYear`, `testShowsTimetable` (a plotted subject and teacher appear), `testShsSemesterSwitch`, `testNotEnrolled` (`You are not enrolled this school year.`), `testTeacherCannotOpen` (403), and first sign-in still forces the password change.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement** (read-only grid markup, no Stimulus controller).
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: student dashboard`.

### Task 13: Final check

- [ ] **Step 1: Run** `php bin/phpunit`, `php bin/console lint:container`, `php bin/console lint:twig templates/`, `php bin/console doctrine:schema:validate` (dev and `--env=test`). Expected: all clean.
- [ ] **Step 2:** In the browser (or ask the user if no browser tool is available): as principal enroll a new learner and sign in as them; as an adviser enroll into the class; request, approve and decline a transfer between two schools.
- [ ] **Step 3: Commit** any fixes: `chore: learner enrollment final checks`.
