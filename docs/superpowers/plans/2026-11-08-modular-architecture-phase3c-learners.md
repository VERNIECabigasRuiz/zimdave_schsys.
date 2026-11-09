# Modular architecture, Phase 3c (Learners: structure and learner screens) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create the `Learners` module with the learner, enrollment and transfer entities and their repositories, move the legacy hook classes behind Access, Schools and Classes into it, and move the learner screens (school, adviser's class, admin list) into it with every action through the Core bus — with nothing a user would notice changing. Transfers, SF1 export and learner ID cards follow in 3d.

**Architecture:** Same patterns as Phases 2–3b. Entities lose their validation and `repositoryClass`; validation moves to command DTOs and form constraints. `LearnerEnrollment` becomes a Learners Application service that checks and applies a change without locking or flushing; its use case handlers take the `learner-<id>` and `learners-school-<id>` locks first (the same keys the legacy `LearnerLock` uses, so the legacy `TransferManager` still excludes them). `LearnerVoter`'s rules move to an Application `LearnerPolicy`, which the handlers check (a refusal is a 403) and which a thin `LearnerVoter` delegates to for the transfer screens that stay legacy until 3d. Queries return arrays whose keys mirror today's entity getters (`learner.user.fullName`, `enrollment.section.label`), so the templates barely change.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Twig, PHPUnit 13, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row `Learners`, sections 5, 6, 8, 11). Owner decisions (2026-11-08): Learners in two plans (3c here; 3d transfers, SF1 and learner IDs); this ticket only migrates (regressions the move causes are fixed, existing flaws are left).

**Branch:** `feature/modular-architecture` (3b committed as `25f8f94`, notes as `4b5d030`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 0. Planning | 2026-11-08 08:32:52 | 08:38:06 | 5m 14s |
| 1. Learners domain, repositories, hooks | | | |
| 2. Learner use cases, queries and policy | | | |
| 3. Learner screens | | | |
| 4. Baselines, docs, comments, deprecations | | | |
| 5. Verification | | | |

## Global Constraints

- Structure only: URLs, route names, HTTP methods, CSRF token ids (`learner-<id>`), form field names (`learner_form[...]`, `enroll_section[...]`, `enrollment_record_form[...]`), flash and refusal messages, activity-log lines, status codes (422 on an invalid enroll/edit form), redirects and access rules stay as today. Table and column names stay; `doctrine:migrations:diff` stays empty; the route table matches HEAD.
- Learners may use Core, Access (Domain, Api), Billing (Domain, Api), Schools (Domain, Api) and Classes (Domain, Api). Legacy may use Learners. Grading data reaches Learners only through a hook (`LearnerGradesInterface`).
- Controllers extend `BusController`; handlers refuse by throwing a `DomainException`; handlers that lock take every lock before any read (learner lock, then school lock, the legacy order). Templates move to `src/Learners/Presentation/templates` (`@Learners`) with their relative paths kept; `templates/learners/id/` stays for 3d.
- Stays legacy until 3d: `TransferManager`, `SchoolTransferController`, `LearnerLock`, `EnrollmentGuard`, the legacy `EnrollSectionFormType` (the transfer request form posts it), `src/Sf1/` apart from `Sf1Remarks`, `src/LearnerId/`, and the download actions (`school_learners_ids`, `school_learners_id`, `school_learners_sf1`, `teacher_class_sf1`, `teacher_class_ids`, `teacher_class_id`). Stays legacy until Grading: `teacher_class_grades`. These move into small legacy controllers with the same routes.
- New code: `declare(strict_types=1)`, PHPStan level 9 with no baseline entries, phpcs PSR-12 (lines ≤ 120), 100% coverage of `src/Learners` (added to `<source>` in `phpunit.dist.xml`).
- Moved tests keep their assertions. New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first) with every setup line under `// Initialize`, the existing helpers, and `createStub` unless the test sets expectations.
- Helper scripts: check the scratchpad for an existing one first (`move3b.py`, `prune_uses.py`, `wrap_long.py`, `compare_routes.py`, the live-check scripts); write new ones there with the Write tool; no inline heredocs; no `sed` for text with backslashes; no `git add -N`. Save the HEAD route dump before the first code change.
- Tests are written per task and run once in Task 5, one command at a time, in Docker (`MSYS_NO_PATHCONV=1` for container paths). Clear `reports/phpstan/.cache` before a PHPStan run whose result counts. Long runs go to the background and are watched directly; leftover servers, monitors and shells are stopped when done.
- PHPStan baseline: prune entries for deleted files, regenerate on a clear cache; the total must not grow and no entry may name a module folder or a file not on `develop`.
- Live-browser check: ask first and confirm headless or visible; serve both sides with the `php -S` router so JavaScript runs; check the saved HTML isn't empty. Comments short and plain; no Claude/Anthropic mention. One commit at the end after approval, through the Base hook.

## Review Focus

1. **Two saves at once.** Enrolling, editing, the SF1 record, the photo, moving, the direct transfer, unenrolling and the password reset each wait for `learner-<id>` (and `learners-school-<id>` where today's code takes it) with "Another change is being saved. Please try again."; a unique clash (same LRN, same learner enrolled twice) answers with that message instead of an error page; a learner moved or unenrolled meanwhile gets today's "This learner was moved. Reload the page and try again." / "This learner is no longer enrolled.". Pinned by the moved `LearnerEnrollmentTest` race cases through the bus (Task 2) and controller cases (Task 3).
2. **Who may do what.** Principal of the school, the section's adviser (edit, photo, record, reset password, unenroll — not LRN change or move), admin (anything, and any learner's page with history), nobody without a current school year; a teacher who advises no section gets 403 on `/teacher/class`. Pinned by the moved `LearnerVoterTest` (now on `LearnerPolicy` and the voter) and the moved controller tests.
3. **Refusals and plan limits.** Every message stays: LRN format, LRN taken ("LRN … belongs to …"), username taken, already enrolled here/elsewhere, section full, plan expired or school inactive, grades exist on unenroll, pending transfer, same/other school on move/transfer. Pinned by the moved `LearnerEnrollmentTest` and controller tests (Tasks 2–3).
4. **What each page shows.** Index search, section filter and paging (50 per page); class list with the Individual-plan seat limit; enroll step 1/2 for new, not enrolled, enrolled here, enrolled elsewhere (with the transfer form for a principal, none for an admin or adviser); show page buttons per role; admin list with the latest school for a learner not enrolled this year. Pinned by the moved controller tests (Task 3).
5. **Legacy users keep working.** The transfer screens (voter attributes, lookup, the transfer request form), the SF1 and ID downloads, the teacher's grades page, the student dashboard's learner details, attendance/grading/nutrition code reading enrollments, and the hooks behind Access, Schools and Classes. Pinned by the legacy suite and `tests/Learners/Integration/Infrastructure/LearnersHooksTest.php` (Task 1).

## Rulings in this plan

- Entities, enums and repositories of the whole module (including `TransferRequest`) move in 3c: the learner screens and the guard read transfers. `Sf1Remarks` moves too (the show and record pages use it); the rest of `src/Sf1/` waits for 3d.
- `EnrollmentException` becomes `App\Learners\Domain\Exception\EnrollmentException`, a Core `DomainException` (code `ENROLLMENT_REFUSED`); the legacy `TransferManager` keeps throwing it.
- The module's checks live in a new `EnrollmentRules` (current year, current section, pending transfer, the moved/no-longer-enrolled check against the ids the page saw). The legacy `EnrollmentGuard` stays, unchanged apart from imports, for `TransferManager` until 3d.
- Learner accounts are created and reset through Access's `AccountDirectoryInterface` (`register`, `setTemporaryPassword`, `isUsernameTaken`), which does exactly what `LearnerEnrollment` does by hand today.
- Commands carry ids from the page: the school (and the adviser's section) of the page's scope, the learner, the section the page showed for the learner (for the moved check), and the acting account. The admin's direct transfer also carries the destination school id, read by the controller before dispatch, so the handler can lock it first; the handler refuses a section not in that school (404, today's message).
- `UniqueLrn` becomes a class constraint on `LearnerDetails` (learner id instead of the entity); same message and path.

---

### Task 1: Learners domain, repositories, hooks

**Files:**
- Move: `src/Entity/{Learner,Enrollment,TransferRequest,TransferStatus,Sf1Remark,LearningModality,SexAtBirth}.php` → `src/Learners/Domain/Model/`; `src/Repository/{Learner,Enrollment,TransferRequest}Repository.php` → `src/Learners/Infrastructure/Repository/Doctrine*Repository.php` with interfaces in `src/Learners/Domain/Model/Repository/`; `src/Service/EnrollmentException.php` → `src/Learners/Domain/Exception/`; `src/Sf1/Sf1Remarks.php` → `src/Learners/Domain/Service/Sf1/Sf1Remarks.php`; `src/Service/SchoolsHooks/CurrentEnrolledLearners.php` → `src/Learners/Infrastructure/Schools/`; `src/Service/ClassesHooks/CurrentSectionLearners.php` → `src/Learners/Infrastructure/Classes/`; `src/Service/AccessHooks/{LearnerAccountLink,LearnerRoleChangeGuard}.php` → `src/Learners/Infrastructure/Access/`
- Create: `src/Learners/Domain/Service/Usage/LearnerGradesInterface.php` (`countForEnrollment(Enrollment): int`), `src/Service/LearnersHooks/CurrentLearnerGrades.php` (legacy, `GradeRepository::countForEnrollment`); exceptions `LearnerNotFoundException` ("This learner is not enrolled here this school year."), `LearnersAccessDeniedException`
- Modify: `config/packages/doctrine.yaml` (Learners mapping), `config/deptrac.yaml` (Learners layers), `config/packages/twig.yaml` (`@Learners`), `config/phpcs.xml` (`src/Learners`, `tests/Learners`), `phpunit.dist.xml` (`src/Learners` in `<source>`); every legacy user of the moved classes (scripted import rewrite; `find()` → `findById()`)
- Move tests: `tests/Service/Sf1RemarksTest.php` → `tests/Learners/Integration/Domain/`
- Test (new): `tests/Learners/Integration/Infrastructure/LearnersHooksTest.php` (each hook answers as before), `tests/Learners/Unit/Domain/` cases for entity behaviour not covered elsewhere

**Interfaces — Produces:**
- `LearnerRepositoryInterface`: `findById`, `findOneByLrn`, `searchByLrnOrName(string, int): list<Learner>`, `findOneByUser`, `add`. `EnrollmentRepositoryInterface`: today's public methods plus `findById`, `add`, `remove`. `TransferRequestRepositoryInterface`: today's public methods plus `findById`.
- `EnrollmentException(string $message)`, code `ENROLLMENT_REFUSED`.

- [ ] **Step 1:** Save the HEAD route dump (scratchpad).
- [ ] **Step 2:** Move the entities and repositories (validation and `repositoryClass` removed, `declare(strict_types=1)`); rewrite legacy imports; move the hooks and add the grades hook.
- [ ] **Step 3:** Write `LearnersHooksTest` and move `Sf1RemarksTest`.
- [ ] **Step 4: Setup checks:** `cache:clear`, `lint:container`, `doctrine:schema:validate`, `doctrine:migrations:diff` empty.

### Task 2: Learner use cases, queries and policy

**Files:**
- Create in `src/Learners/Application/`: `Service/LearnerPolicy.php` (today's voter rules, by account and object), `Service/LearnersScope.php` (school, current year, adviser's section, section/learner lookups with today's 404 messages), `Service/EnrollmentRules.php`, `Service/LearnerEnrollment.php` (moved; no lock, no flush; accounts through `AccountDirectoryInterface`; grades through `LearnerGradesInterface`), `Service/LearnerLookup.php` + `LookupResult` + `LookupKind` (moved), `Transformer/{Learner,Enrollment,TransferRequest}Transformer.php`
- Commands (`UseCase/Command/`): `LearnerDetails` (every form field with today's constraints, `UniqueLrn`), `EnrollLearnerCommand`, `UpdateLearnerCommand`, `UpdateEnrollmentRecordCommand` (with today's remark callback), `UpdateLearnerPhotoCommand` (file path or null), `MoveLearnerCommand`, `TransferLearnerCommand`, `UnenrollLearnerCommand`, `ResetLearnerPasswordCommand`, and their handlers
- Queries (`Query/Command/`): `ListLearnersCommand` (search, section, page; 50 per page), `GetClassListCommand`, `GetAdvisoryCommand` (the adviser's scope), `LookUpLrnCommand`, `GetLearnerCommand` (details, current, history, transfers, remarks, move sections, transfer schools and sections, the `can` flags), `GetLearnerFormCommand`, `GetEnrollmentRecordCommand`, `ListAdminLearnersCommand`, and their handlers
- Move tests: `tests/Service/{LearnerEnrollmentTest,LearnerLookupTest}.php` → `tests/Learners/Integration/Application/` (through a flushing helper `SavingLearnerEnrollment`; lock and race cases through the bus); `tests/Security/LearnerVoterTest.php` → `tests/Learners/Integration/Application/LearnerPolicyTest.php` (same cases on the policy) plus a voter delegation case
- Test (new): `tests/Learners/Integration/Application/LearnerHandlersTest.php` (locks first, busy and unique-clash answers, moved/no-longer-enrolled, 403 and 404 refusals, each query's shape)

**Interfaces — Produces:** every command carries `actorId`, the page's `schoolId` and `fixedSectionId` (null on the school pages), and the ids it acts on; refusals are `EnrollmentException`, `PlanLimitException`, `LearnersAccessDeniedException` (403), `LearnerNotFoundException` / `SectionNotFoundException` / `SchoolYearNotFoundException` (404), or `ResourceBusyException`.

- [ ] **Step 1:** Move and write the tests above.
- [ ] **Step 2:** Implement; `DemoFixtures` flushes after `enrollNew()`.
- [ ] **Step 3: Setup checks:** `lint:container`.

### Task 3: Learner screens

**Files:**
- Move: `src/Controller/{AbstractLearnerController,SchoolLearnerController,TeacherClassController,LearnerScope}.php`, `src/Controller/Admin/LearnerController.php` → `src/Learners/Presentation/Controller/` (`AdminLearnerController`); `src/Form/{LearnerFormType,EnrollmentRecordFormType}.php` → `src/Learners/Presentation/Form/` (binding the commands) plus a new `EnrollFormType` (block prefix `enroll_section`); `src/Validator/UniqueLrn*.php` → `src/Learners/Presentation/Validation/`; `src/Security/Voter/LearnerVoter.php` → `src/Learners/Presentation/Security/` (delegating to `LearnerPolicy`); `templates/learners/*.html.twig`, `templates/admin/learners/index.html.twig` → `src/Learners/Presentation/templates/` (includes and the student dashboard's includes updated to `@Learners`)
- Create (legacy, same routes): `src/Controller/LearnerDownloadController.php` (the six download routes), `src/Controller/TeacherClassGradesController.php` (`teacher_class_grades`)
- Move tests: `tests/Controller/{SchoolLearnerControllerTest,TeacherClassControllerTest}.php`, `tests/Controller/Admin/LearnerControllerTest.php` → `tests/Learners/Functional/`
- Test (new): controller cases for the busy and unique-clash answers, a moved learner, and each new 403/404 path; `tests/Learners/Unit/Presentation/UniqueLrnValidatorTest.php`

- [ ] **Step 1:** Move and write the tests.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `lint:container`, `lint:twig`, route table identical to HEAD.

### Task 4: Baselines, docs, comments, deprecations

- [ ] **Step 1:** phpcbf, then phpcs clean; PHPStan on the module folders (clear cache), then baseline regeneration; deptrac 0; ESLint and Stylelint clean.
- [ ] **Step 2: Docs.** `CLAUDE.md`: the Learners module (entities, services, hooks it implements, `LearnerGradesInterface` in `src/Service/LearnersHooks/`, what stays legacy until 3d), the learner locks taken by the handlers; `SchoolFixturesTrait` notes if it changes.
- [ ] **Step 3: Comment sweep and deprecation check** (`debug:container --deprecations`) over everything touched.

### Task 5: Verification

- [ ] **Step 1: Scoped run** of the affected tests (learner, transfer, attendance, grading, nutrition, card slip, dashboard and hook tests), then fix, re-run only failures.
- [ ] **Step 2: Coverage** of `src/Learners` → nothing uncovered.
- [ ] **Step 3: Full runs on the final code, one at a time:** `make test all NO_PARALLEL=1`, `make test-feature`, `make regression-test`; schema valid, diff empty; route table identical to HEAD.
- [ ] **Step 4: Fresh whole-change review** (most capable model, read-only); fix Critical/Important (and anything a user would see) with a failing test first; full runs again.
- [ ] **Step 5: Ask the owner before the live-browser check, and whether headless or visible.** If headless: HEAD vs branch on the same dev data (reset between sides): learners index (search, section filter), class list, enroll a new LRN, an existing unenrolled learner, one enrolled here, one enrolled elsewhere (principal sees the transfer form), the show page as principal, adviser and admin, edit (and a refused LRN), the SF1 record, photo upload and removal, move, unenroll, reset password, the adviser's class pages, the admin learner search, the SF1 and ID downloads (status and file name), and the student dashboard; then restore the dev data.
- [ ] **Step 6: Report** and propose the commit; commit only after approval:

```
Move learners and their screens into the Learners module

Create the Learners module with the learner, enrollment and transfer
entities and their repositories, move the hook classes behind Access,
Schools and Classes into it, and move the learner screens of schools,
advisers and administrators into it, with every change through the
bus. Grades reach it through a hook. Pages, messages and URLs are
unchanged.
```
