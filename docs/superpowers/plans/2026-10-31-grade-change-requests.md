# Per-School Grading Open/Close and Grade Change Requests Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each school opens and closes its own grading periods (principal or admin), and a teacher corrects a locked grade by sending a change request (learner, old → new, reason) that the principal approves (applied at once) or declines with a note.

**Architecture:** Part 1 replaces the global `GradingPeriod::$open` with a per-school `GradeOpening` row (same shape as `GradeLock`); `GradeRules` reads it, `GradeRecorder` writes it, `GradeVoter::OPEN` gates it. Part 2 adds `GradeChangeRequest` + `GradeChangeLine` (modelled on `TransferRequest`), a `GradeChangeManager` service doing every write under the existing Symfony locks, two new voter attributes, and teacher/principal screens.

**Tech Stack:** Symfony 8.1, PHP 8.5, Doctrine ORM + migrations, Twig, Symfony Form + Validator, symfony/lock, PHPUnit 13 (KernelTestCase / WebTestCase).

**Spec:** `docs/superpowers/specs/2026-10-31-grade-change-requests-design.md`

## Global Constraints

- Branch `feature/grade-changes` (off `feature/grades`). Schema only via `php bin/console make:migration` + `doctrine:migrations:migrate` (dev **and** `--env=test`). Never `schema:update`. Back up the dev DB (`mysqldump` to `C:\xampp\htdocs\db_backups\`) before migrating it.
- Run tests with `php bin/phpunit <path>` (PowerShell). Never two phpunit runs at once (they share `zimdave_schsys_test`). The full suite takes ~6 minutes.
- Tests use `SchoolFixturesTrait` (`clearSchoolData()` must delete every new entity, children before parents) and `ClockSensitiveTrait::mockTime('2025-08-22 10:00:00')`; fixture school year via `schoolYear(2025, current: true)` + `rebuildGradingPeriods()`.
- `{Term 1}` below = `GradingPeriod::getLabel()`; `{class}` = `GradeRecorder::classLabel($section, $subject)` ("Mathematics – Grade 8 – HONEST").
- Exact copy, Part 1: "{Term 1} is already open." · "{Term 1} is already closed." · "{Term 1} is locked. Unlock it first." · flashes "{Term 1} is open for grading." / "{Term 1} grading is closed." · status line "Open for grading since {M j} by {name}" · admin "Open in {n} of {m} schools" + "Each school opens grading on its Grades page." · "Close grading in every school before switching between terms and quarters." · log "Opened {Term 1} grading" / "Closed {Term 1} grading" (admin: suffix " at {school name}").
- Exact copy, Part 2: "This class isn't graded here." · "{Term 1} isn't locked — change the grades on the sheet." · "{Term 1} isn't open for grading." · "You already have a pending change request for this class." · "Enter a grade from 60 to 100." · "Enter at least one corrected grade." · "Write the reason for the change (at least 10 characters)." · "This request has already been decided." · "Grades have changed since this request was sent. Decline it so the teacher can send a new one." · "Write a note for the teacher (at least 5 characters)." · flashes "Your change request was sent to the principal." / "Your change request was withdrawn." / "Approved. The new grades are in." / "Declined. The teacher will see your note." · sheet line "Your change request is waiting for the principal." · logs "Requested a change of {Term 1} grades of {class}" / "Approved …" / "Declined …" / "Withdrew …" (same tail).
- Reason: trimmed, 10–1000 chars. Decline note: trimmed, 5–1000 chars. Grade values 60–100.
- Bare POST buttons use `#[IsCsrfTokenValid]`; POSTs with fields are Symfony Forms. Attributes for routes, no YAML.
- Never stage `assets/controllers.json` or `config/reference.php`, nor the unrelated attendance changes already in the working tree (`assets/styles/admin.css` attendance line, `src/Attendance/Sf2Writer.php`, `templates/attendance/*`, their tests) — stage task files by path. Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. The school closes a period while a teacher has the sheet open — the teacher's save is refused with "{Term 1} isn't open for grading." and nothing changes. (Test in Task 3.)
2. One school opens Term 1 — another school's classes stay Closed and its teachers can't save. (Test in Task 2.)
3. A teacher withdraws a request at the same moment the principal approves it — the second action is refused "This request has already been decided." and grades are untouched if the withdraw won. (Test in Task 5: approve after withdraw.)
4. The load is reassigned to another teacher while a request is pending — the original teacher can still withdraw; the new teacher can't send another until it's decided. (Tests in Tasks 5 and 6.)
5. The class list changes (a learner joins or drops) between opening the request form and sending it — the form is refused with the roster-changed message, as the sheet is. (Test in Task 7.)

---

# Part 1 — Per-School Grading Open/Close

### Task 1: `GradeOpening` entity, repository and data-carrying migration

**Files:**
- Create: `src/Entity/GradeOpening.php`, `src/Repository/GradeOpeningRepository.php`, `migrations/Version2026103…php` (generated)
- Modify: `tests/SchoolFixturesTrait.php` (cleanup list + helper)
- Test: `tests/Repository/GradeOpeningRepositoryTest.php`

**Interfaces:**
- Produces: `GradeOpening(School $school, GradingPeriod $period, ?User $openedBy, \DateTimeImmutable $openedAt)` with getters `getSchool()`, `getGradingPeriod()`, `getOpenedBy(): ?User`, `getOpenedAt()`; unique (school, grading_period).
- Produces: `GradeOpeningRepository::findFor(School, GradingPeriod): ?GradeOpening`, `findOpenPeriodIds(School, SchoolYear): list<int>`, `existsForSchoolYear(SchoolYear): bool`, `countSchoolsOpen(GradingPeriod): int`.
- Produces (tests): `SchoolFixturesTrait::openGrading(School $school, GradingPeriod $period): GradeOpening` (persists + flushes, openedBy null).

- [ ] **Step 1: Write `GradeOpeningRepositoryTest`** — `testFindForAndOpenPeriodIds` (open term 1 and 3 for school A → `findOpenPeriodIds(A, sy) === [t1, t3]`, B → `[]`, `findFor(B, t1) === null`); `testExistsForSchoolYear` (false, then true after one opening); `testCountSchoolsOpen` (A and B open t1 → 2, t2 → 0).
- [ ] **Step 2: Run** `php bin/phpunit tests/Repository/GradeOpeningRepositoryTest.php` — Expected: FAIL (class `GradeOpening` not found).
- [ ] **Step 3: Implement the entity and repository** as `GradeLock`/`GradeLockRepository` are written (`onDelete: CASCADE` for school and period, `SET NULL` for openedBy). Add `GradeOpening::class` to `clearSchoolData()` before `GradeLock::class`, and the `openGrading()` helper.
- [ ] **Step 4: Generate and edit the migration** — `php bin/console make:migration --no-interaction`; after the generated `CREATE TABLE grade_opening …` in `up()`, add
  `$this->addSql('INSERT INTO grade_opening (school_id, grading_period_id, opened_by_id, opened_at) SELECT s.id, gp.id, NULL, NOW() FROM school s CROSS JOIN grading_period gp WHERE gp.open = 1');`
  Leave `grading_period.open` in place (Task 2 drops it).
- [ ] **Step 5: Migrate** — back up the dev DB, then `php bin/console doctrine:migrations:migrate --no-interaction` and the same with `--env=test`. Check: `SELECT COUNT(*) FROM grade_opening` = (schools × open periods) in dev.
- [ ] **Step 6: Run** the repository test — Expected: PASS.
- [ ] **Step 7: Commit** — `feat: per-school grade openings (table and data carried from the shared switch)`.

### Task 2: Grading status reads the school's opening; the shared switch goes

**Files:**
- Modify: `src/Grading/GradeRules.php` (`status`, `statusFor`, `canLock`, constructor), `src/Grading/SchoolGradeOverview.php:33-75`, `src/Grading/SectionGradeGrid.php:29-38`, `src/Controller/SchoolGradeController.php` (`overview`, `selectPeriod`), `src/Controller/TeacherClassController.php:54`, `src/Entity/GradingPeriod.php` (remove `$open`, `isOpen`, `setOpen`), `src/Entity/SchoolYear.php` (remove `hasOpenGradingPeriod`, the `setOpen(false)` in `rebuildGradingPeriods`), `src/Controller/Admin/SchoolYearController.php` (remove `toggleGradingPeriod`; guard uses repository; `index` passes counts), `templates/admin/school_years/index.html.twig:35-53`, `templates/grades/school.html.twig:30`, new migration dropping `grading_period.open`
- Test: `tests/Grading/GradeRulesTest.php`, `tests/Grading/GradeRecorderTest.php`, `tests/Controller/TeacherGradeControllerTest.php`, `tests/Controller/SchoolGradeControllerTest.php`, `tests/Controller/Admin/SchoolYearControllerTest.php`

**Interfaces:**
- Consumes: Task 1 repository and `openGrading()`.
- Produces: `GradeRules::statusFor(bool $open, bool $locked, bool $unlocked): ClassGradeStatus`; `GradeRules::isOpenFor(School, GradingPeriod): bool`; `SectionGradeGrid::defaultPeriod(School $school, SchoolYear $schoolYear): ?GradingPeriod`; template var `opening` (`?GradeOpening`) on `grades/school.html.twig`; admin template var `open_counts` (`array<int periodId, int>`) and `school_count` (int).

- [ ] **Step 1: Rewrite the tests to the new source of "open"** — replace every `$period->setOpen(true)` with `$this->openGrading($this->school, $period)` and every `setOpen(false)` with removing that opening (`$this->em->remove(...)` + flush). Add:
  - `GradeRulesTest::testOpenInOneSchoolOnly`: open t1 for school A → A's class `Open`, school B's class `Closed`; `canLock(B, t1)` false.
  - `GradeRecorderTest::testOtherSchoolCannotSaveWhenOnlyOneIsOpen`: B's teacher `saveSheet` throws "Term 1 isn't open for grading.".
  - `SchoolYearControllerTest::testShowsSchoolsOpenCount`: 2 schools, 1 opening on t1 → page contains "Open in 1 of 2 schools" and "Each school opens grading on its Grades page."; no `admin_grading_period_toggle` form.
  - `SchoolYearControllerTest::testSwitchingSystemRefusedWhileASchoolHasAPeriodOpen`: error "Close grading in every school before switching between terms and quarters."
  - `SchoolGradeControllerTest` status line "Open for grading since Aug 22 by" when opened with a user; "Closed for grading" when not.
- [ ] **Step 2: Run** the five test files — Expected: FAIL (helper usage compiles; `statusFor`/`isOpen` assertions and new tests fail).
- [ ] **Step 3: Implement** — `GradeRules` gets `GradeOpeningRepository`; `status()` = `statusFor($this->isOpenFor($school, $period), locked, unlocked)`; `canLock()` uses `isOpenFor`. `SchoolGradeOverview::build()` loads the opening once and passes the bool. `SectionGradeGrid::defaultPeriod()` takes the school and picks the earliest id in `findOpenPeriodIds()`; update both callers (adviser: the section's school). Remove the global flag from `GradingPeriod`/`SchoolYear` and the admin toggle route + form; the admin edit guard uses `existsForSchoolYear($schoolYear)` (read before handling the request, as now). Admin index computes `open_counts` via `countSchoolsOpen()` and `school_count` via `SchoolRepository::count([])`.
- [ ] **Step 4: Generate the drop migration** — `php bin/console make:migration --no-interaction` (should contain only `ALTER TABLE grading_period DROP open`); migrate dev (after backup) and `--env=test`.
- [ ] **Step 5: Run** the five test files, then `php bin/phpunit` (full) — Expected: all PASS; `php bin/console lint:twig templates` and `lint:container` OK; `grep -rn "isOpen()\|setOpen(" src templates tests` finds only `EntityManager::isOpen` uses.
- [ ] **Step 6: Commit** — `feat: grading is open per school, not for every school at once`.

### Task 3: Principal and admin open and close grading

**Files:**
- Modify: `src/Grading/GradeRecorder.php`, `src/Security/Voter/GradeVoter.php`, `src/Controller/SchoolGradeController.php`, `templates/grades/school.html.twig`
- Test: `tests/Grading/GradeRecorderTest.php`, `tests/Security/GradeVoterTest.php`, `tests/Controller/SchoolGradeControllerTest.php`, `tests/Controller/TeacherGradeControllerTest.php`

**Interfaces:**
- Consumes: Task 1/2.
- Produces: `GradeRecorder::openPeriod(School, GradingPeriod, User $by): void`, `GradeRecorder::closePeriod(School, GradingPeriod, User $by): void` (both `@throws GradingException`); `GradeVoter::OPEN = 'GRADE_OPEN'` on `School`; routes `school_grades_open` (POST `/schools/{id}/grades/{period}/open`, CSRF id `"grade-open-" ~ period`) and `school_grades_close` (POST `/schools/{id}/grades/{period}/close`, CSRF id `"grade-close-" ~ period`).

- [ ] **Step 1: Write the failing tests**
  - `GradeRecorderTest`: `testOpenAndClosePeriod` (opening row created with `openedBy`; closed → row gone; logs "Opened Term 1 grading" / "Closed Term 1 grading"); `testAdminOpeningLogsTheSchool` (log ends " at Pagsabungan National High School"); `testOpenRefusals` ("Term 1 is already open.", "Term 1 is already closed.", locked → "Term 1 is locked. Unlock it first." for both open and close).
  - `GradeVoterTest::testOpen`: own principal ✓, admin ✓, other school's principal ✗, teacher ✗.
  - `SchoolGradeControllerTest`: `testPrincipalOpensAndCloses` (buttons "Open Term 1 for grading" then "Close Term 1 grading", flashes exact); `testAdminOpens`; `testOpenButtonsHiddenWhileLocked`; `testTeacherCannotOpen` (403).
  - `TeacherGradeControllerTest::testSaveRefusedWhenClosedWhileEditing`: GET the open sheet, close via recorder, submit → error "Term 1 isn't open for grading.", no `Grade` rows. (Review Focus 1)
- [ ] **Step 2: Run** the four files — Expected: FAIL (undefined method `openPeriod`).
- [ ] **Step 3: Implement** — both methods under `$this->locked('grade-lock-'.$school->getId(), …)` (exclusive), refusals in the order locked → already; the log suffix when `$by` is an admin. Voter: `self::OPEN => $isAdmin || $this->isPrincipalOf($user, $subject)`. Controller actions `#[IsCsrfTokenValid]`, `denyAccessUnlessGranted(GradeVoter::OPEN, $school)`, `requirePeriod()`, flash, `backTo()`. Template: pass `can_open` and render the buttons when `can_open and not lock`.
- [ ] **Step 4: Run** the four files — Expected: PASS.
- [ ] **Step 5: Commit** — `feat: principal and admin open and close grading for their school`.

---

# Part 2 — Grade Change Requests

### Task 4: Change request entities and migration

**Files:**
- Create: `src/Entity/GradeChangeStatus.php`, `src/Entity/GradeChangeRequest.php`, `src/Entity/GradeChangeLine.php`, `src/Repository/GradeChangeRequestRepository.php`, migration (generated)
- Modify: `tests/SchoolFixturesTrait.php` (add `GradeChangeLine::class`, `GradeChangeRequest::class` first in the cleanup list)
- Test: `tests/Entity/GradeChangeRequestTest.php`, `tests/Repository/GradeChangeRequestRepositoryTest.php`

**Interfaces:**
- Produces: `enum GradeChangeStatus: string { Pending='pending'; Approved='approved'; Declined='declined'; Withdrawn='withdrawn'; label() }`.
- Produces: `GradeChangeRequest(Section $section, Subject $subject, GradingPeriod $period, User $requestedBy, string $reason, \DateTimeImmutable $createdAt)`; `addLine(Enrollment $enrollment, ?int $from, int $to): void`; `getLines(): Collection<GradeChangeLine>`; `getStatus()`, `isPending()`, `getReason()`, `getRequestedBy()`, `getSection()`, `getSubject()`, `getGradingPeriod()`, `getCreatedAt()`, `getDecidedBy()`, `getDecidedAt()`, `getDecisionNote()`; `approve(User, \DateTimeImmutable)`, `decline(User, \DateTimeImmutable, string $note)`, `withdraw(\DateTimeImmutable)` — each throws `\LogicException` unless pending. Lines `cascade: ['persist']`, ordered by id.
- Produces: `GradeChangeLine` getters `getEnrollment()`, `getFromValue(): ?int`, `getToValue(): int`; unique (request, enrollment).
- Produces: `GradeChangeRequestRepository::findPendingFor(Section, Subject, GradingPeriod): ?GradeChangeRequest`, `findForClass(Section, Subject, GradingPeriod): list` (newest first), `findPendingForSchool(School, SchoolYear): list` (oldest first), `findDecidedForSchool(School, SchoolYear): list` (newest first), `countPendingForSchool(School, SchoolYear): int`.

- [ ] **Step 1: Write the tests** — `GradeChangeRequestTest` (plain PHPUnit): new request is pending; `approve` sets decidedBy/At and status; `decline` stores the note; `withdraw` sets decidedAt only; a second transition throws `\LogicException`. `GradeChangeRequestRepositoryTest`: pending/decided splits and order, `findPendingFor` ignores decided ones, count, other school excluded.
- [ ] **Step 2: Run** both — Expected: FAIL (classes missing).
- [ ] **Step 3: Implement** entities/repository (FKs as the spec: section/period `CASCADE`, subject/requestedBy `RESTRICT`, decidedBy `SET NULL`, line → request and enrollment `CASCADE`; index on (section_id, subject_id, grading_period_id, status); reason and note `TEXT`; values `smallint`). School-year filters go through `section.schoolYear`.
- [ ] **Step 4: Migrate** — `make:migration --no-interaction`, migrate dev (after backup) and `--env=test`.
- [ ] **Step 5: Run** both — Expected: PASS.
- [ ] **Step 6: Commit** — `feat: grade change request and line entities`.

### Task 5: `GradeChangeManager`

**Files:**
- Create: `src/Grading/GradeChangeManager.php`
- Modify: `src/Grading/GradeRecorder.php` (make the private `locked()` reusable: extract to `src/Grading/GradeLocks.php` with `class(Section, Subject, callable)` and `school(School, callable, bool $shared = false)`, and use it in both services)
- Test: `tests/Grading/GradeChangeManagerTest.php`

**Interfaces:**
- Consumes: Task 4 entities/repository; `GradeRules::status/sheet/isGradable/refusalToEdit`; `GradeRepository::findForEnrollments`; `Grade::change($value, User, at)` / `new Grade(...)`; `ActivityLogger::log`; `ClockInterface`.
- Produces: `GradeChangeManager::request(TeachingLoad $load, GradingPeriod $period, array $values /* array<int enrollmentId, ?int> */, string $reason, User $by): GradeChangeRequest`; `approve(GradeChangeRequest, User $by): void`; `decline(GradeChangeRequest, User $by, string $note): void`; `withdraw(GradeChangeRequest, User $by): void` — all `@throws GradingException` with the Global Constraints copy.

- [ ] **Step 1: Write the failing tests** (setup as `GradeRecorderTest`: 3 learners, Arnol 78, Bañez 85, Cruz no grade; t1 opened then locked):
  - `testRequestRecordsOnlyChangedLines`: values `[arnol => 82, bañez => 85, cruz => 90]` → 2 lines: Arnol 78→82, Cruz null→90; status pending; reason trimmed; log "Requested a change of Term 1 grades of Mathematics – Grade 8 – HONEST".
  - `testRequestRefusals` (data provider): class open → "Term 1 isn't locked — change the grades on the sheet."; class unlocked → same; period closed and unlocked-free → "Term 1 isn't open for grading."; pending exists → "You already have a pending change request for this class."; 59 → "Enter a grade from 60 to 100."; all equal/blank → "Enter at least one corrected grade."; reason "   too short " → "Write the reason for the change (at least 10 characters)."; dropped learner's value ignored (and alone → "Enter at least one corrected grade.").
  - `testApproveAppliesGrades`: Arnol becomes 82 with `updatedBy` = teacher, Cruz gets a new 90 row; status approved by principal; log "Approved …".
  - `testApproveRefusedWhenStale`: after the request, unlock the class and save Arnol 80 → approve throws the stale message; Arnol stays 80, Cruz still ungraded, request still pending. Second case: Cruz dropped after the request → same message.
  - `testApproveWorksWhenPeriodUnlocked`: unlock whole period, grades unchanged → approve applies.
  - `testDeclineNeedsANote` ("Write a note for the teacher (at least 5 characters)." for "ok"), then decline with "Attach the class record" → declined, note kept, grades untouched, log "Declined …".
  - `testWithdrawThenApproveRefused`: withdraw → withdrawn, log "Withdrew …"; approve → "This request has already been decided."; grades untouched. (Review Focus 3)
  - `testNewTeacherBlockedWhilePending`: reassign the load to another teacher → their `request()` throws "You already have a pending change request for this class."; the original teacher can still `withdraw`. (Review Focus 4, manager side)
- [ ] **Step 2: Run** `php bin/phpunit tests/Grading/GradeChangeManagerTest.php` — Expected: FAIL (class missing).
- [ ] **Step 3: Extract `GradeLocks`** from `GradeRecorder::locked()` (same lock names `grades-{section}-{subject}` and `grade-lock-{school}`, same shared flag), switch `GradeRecorder` to it, run `tests/Grading` — Expected: PASS (refactor only).
- [ ] **Step 4: Implement `GradeChangeManager`** — `request`: class lock, then shared school lock; check gradable → status (`Locked` required: `Open`/`Unlocked` → "isn't locked" copy, `Closed` → `refusalToEdit`) → pending → reason → values (only `editable` sheet learners; ignore blanks and unchanged) → at least one line. `approve`: class lock then **exclusive** school lock; `refresh()` the request; pending check; stale check over all lines against `findForEnrollments` and the sheet's `editable`; then apply, `approve()`, log, one flush. `decline`/`withdraw`: school lock, refresh, pending check, note check (decline), transition, log, flush.
- [ ] **Step 5: Run** the test — Expected: PASS.
- [ ] **Step 6: Commit** — `feat: grade change requests — send, approve, decline, withdraw`.

### Task 6: Voter attributes

**Files:**
- Modify: `src/Security/Voter/GradeVoter.php`
- Test: `tests/Security/GradeVoterTest.php`

**Interfaces:**
- Produces: `GradeVoter::CHANGE_WITHDRAW = 'GRADE_CHANGE_WITHDRAW'` and `GradeVoter::CHANGE_DECIDE = 'GRADE_CHANGE_DECIDE'` on `GradeChangeRequest`.

- [ ] **Step 1: Write the failing tests** — `testChangeWithdraw`: requester ✓ (also after the load moved to another teacher), other teacher ✗, principal ✗, admin ✗. `testChangeDecide`: the request's school's principal ✓, other school's principal ✗, admin ✗, requester ✗.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** — `supports()` adds the two attributes for `GradeChangeRequest`; withdraw = `requestedBy` id equals user id; decide = `!$isAdmin && isPrincipalOf($user, $request->getSection()->getSchool())`. Update the `@extends Voter<…>` docblock.
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Commit** — `feat: who may withdraw and decide grade change requests`.

### Task 7: Teacher screens

**Files:**
- Create: `src/Form/GradeChangeFormType.php`, `src/Form/GradeChange.php` (DTO), `templates/grades/change.html.twig`, `templates/grades/_change_requests.html.twig`
- Modify: `src/Controller/TeacherGradeController.php`, `templates/grades/sheet.html.twig`, `assets/styles/admin.css` (only if the badges need a grey `badge--muted`; reuse existing badge classes otherwise)
- Test: `tests/Controller/TeacherGradeControllerTest.php`

**Interfaces:**
- Consumes: Tasks 4–6.
- Produces: routes `teacher_grades_change` (GET|POST `/teacher/grades/{load}/{period}/change`) and `teacher_grades_change_withdraw` (POST `/teacher/grades/changes/{request}/withdraw`, CSRF id `"grade-change-withdraw-" ~ request`); partial `grades/_change_requests.html.twig` with vars `requests` (list), `can_withdraw` (bool), reused by Task 8.
- Produces: `GradeChange { array<int, ?int> $grades; string $reason }`, `GradeChange::of(list<SheetLearner>)` (all editable ids → null); `GradeChangeFormType` = `grades` collection exactly as `GradeSheetFormType` (reuse its range constraint and its `matchRoster` PRE_SUBMIT listener — make that listener a public static or move it to a shared trait) + `reason` `TextareaType` (`Length(min: 10, max: 1000)` with the reason copy, `NotBlank`, `trim` default).

- [ ] **Step 1: Write the failing tests**
  - `testRequestButtonOnlyWhenLocked`: open sheet → no "Request grade change" link; locked → link to `/teacher/grades/{load}/{period}/change`.
  - `testSendChangeRequest`: GET form shows current grades; submit Arnol 82 + reason "Encoding error on Arnol's quiz total" → redirect to sheet, flash "Your change request was sent to the principal.", sheet shows "Your change request is waiting for the principal.", the card lists "ARNOL" with "78 → 82" and badge "Pending", no request button.
  - `testChangeFormValidation`: reason "short" → "Write the reason for the change (at least 10 characters)."; 101 → "Enter a grade from 60 to 100."; nothing changed → "Enter at least one corrected grade.".
  - `testChangeFormRefusedWhenClassListChanged`: GET form, enroll a new learner, submit → roster-changed message, no request saved. (Review Focus 5)
  - `testChangeFormRedirectsWhenNotLocked`: open period → redirect to sheet with flash "Term 1 isn't locked — change the grades on the sheet.".
  - `testWithdraw`: POST withdraw → flash "Your change request was withdrawn.", card shows "Withdrawn", button back.
  - `testDeclinedShowsNote`: declined via manager with note → card shows "Declined" and the note.
  - `testOtherTeacherCannotRequestOrWithdraw`: 403 on both routes.
- [ ] **Step 2: Run** the file — Expected: FAIL (route not found).
- [ ] **Step 3: Implement** — `change()` action: same load/period lookup and `GRADE_EDIT` check as `sheet()`; if the status isn't `Locked` or a pending request exists, flash the refusal and redirect to the sheet; on valid submit call `GradeChangeManager::request()`, catching `GradingException` into a form error. `withdraw()` action: `CHANGE_WITHDRAW`, manager, flash, redirect to that class's sheet (look up the load by section+subject; if none, to `teacher_grades`). `sheet()` passes `change_requests` (`findForClass`) and `pending` (bool). Lines render "{SF1 name} · {from ?? '—'} → {to}"; badges: Pending `badge--amber`, Approved `badge--green`, Declined `badge--rose`, Withdrawn plain `badge`.
- [ ] **Step 4: Run** the file — Expected: PASS.
- [ ] **Step 5: Commit** — `feat: teachers request grade changes on a locked sheet`.

### Task 8: Principal and admin screens

**Files:**
- Create: `src/Controller/SchoolGradeChangeController.php`, `src/Form/GradeChangeDeclineFormType.php`, `templates/grades/changes.html.twig`
- Modify: `templates/grades/school.html.twig` (button with count), `src/Controller/SchoolGradeController.php` (pass `pending_changes`), `src/Controller/Principal/DashboardController.php` + `templates/principal/dashboard.html.twig` (card), create `templates/grades/_changes_waiting_card.html.twig`
- Test: `tests/Controller/SchoolGradeChangeControllerTest.php`, `tests/Controller/Principal/PrincipalAreaTest.php`

**Interfaces:**
- Consumes: Tasks 4–7 (`_change_requests` line rendering may be shared as a macro).
- Produces: routes `school_grade_changes` (GET `/schools/{id}/grade-changes?status=pending|decided`, default pending; `#[IsGranted(SchoolVoter::MANAGE, 'school')]` on the class), `school_grade_change_approve` (POST `/schools/{id}/grade-changes/{request}/approve`, CSRF id `"grade-change-approve-" ~ request`), `school_grade_change_decline` (POST `/schools/{id}/grade-changes/{request}/decline`, form `GradeChangeDeclineFormType` with `note` textarea, `Length(min: 5, max: 1000)` + note copy, one form per pending request named `decline_{id}`).

- [ ] **Step 1: Write the failing tests**
  - `testPendingList`: shows class "Mathematics – Grade 8 – HONEST", "Term 1", teacher name, reason, "ARNOL" line "78 → 82", Approve and Decline for the principal.
  - `testApprove`: flash "Approved. The new grades are in.", Arnol's grade 82, request moves to the Decided tab with "Approved".
  - `testApproveStale`: error flash with the stale copy, nothing applied.
  - `testDecline`: note "ok" → field error (note copy); note "Attach the class record" → flash "Declined. The teacher will see your note.", Decided tab shows the note.
  - `testAdminSeesButCannotDecide`: page 200 without Approve/Decline; POST approve → 403.
  - `testOtherSchoolsRequest404`: approve a request of another school via this school's URL → 404.
  - `testTeacherForbidden`: GET → 403.
  - `testGradesPageButtonCount`: `/schools/{id}/grades` has "Change requests (1)".
  - Dashboard: `testGradeChangeCard`: "Grade change requests waiting: 1" linking to `school_grade_changes`; absent when 0.
- [ ] **Step 2: Run** both files — Expected: FAIL.
- [ ] **Step 3: Implement** — request lookup by id with `$request->getSection()->getSchool() === $school` else 404; decide actions call `denyAccessUnlessGranted(GradeVoter::CHANGE_DECIDE, $request)`; `GradingException` → error flash; redirect to `school_grade_changes`. The card mirrors `transfers/_waiting_card.html.twig` (avatar letter "G", small "Approve or decline").
- [ ] **Step 4: Run** both files — Expected: PASS.
- [ ] **Step 5: Commit** — `feat: principal reviews, approves and declines grade change requests`.

### Task 9: Whole-branch check

- [ ] **Step 1:** `php bin/phpunit` — Expected: OK, all tests.
- [ ] **Step 2:** `php bin/console lint:twig templates`, `lint:container`, `doctrine:schema:validate` (dev and `--env=test`) — Expected: all OK, schema in sync.
- [ ] **Step 3:** `vendor/bin/php-cs-fixer fix --dry-run --diff` if installed — Expected: no changes (fix and recommit otherwise).
- [ ] **Step 4:** In the running app (`http://localhost:8081/`): as principal open Term 1, lock it; as teacher send a change request; as principal approve; confirm the grade on the sheet and the Decided tab. Note the result in the final report.
