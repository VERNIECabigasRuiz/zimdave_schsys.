# Modular architecture, Phase 4b (Grading core) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create the `Grading` module with the grading entities and repositories, grade sheets, opening, closing and locking of grading periods, class unlocks, and the teacher's, adviser's and school's grade pages, every action through the Core bus, with nothing a user would notice changing. Grade change requests and card slips keep their services and screens in legacy code until 4c.

**Architecture:** Same patterns as 4a. Entities lose `repositoryClass`. `GradeRecorder` becomes an Application service that checks and applies a change without locking or flushing. Its use case handlers take today's lock keys before reading anything:
- a sheet save takes `grades-<section>-<subject>` and a *shared* hold on `grade-lock-<school>`;
- the principal's open, close, lock and unlock changes take `grade-lock-<school>`.

A sheet save reads the class's grades fresh, because the sheet page's query runs first in the same request. `GradeVoter` becomes an Application `GradePolicy`; only its two change-request attributes stay in the legacy voter until 4c. Grading reads Classes and Learners data only through their public APIs, which this phase widens.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Symfony Lock (flock, shared locks), Twig, PHPUnit 13, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row `Grading`, sections 5, 6, 8, 11). Owner decisions (2026-11-08):
- Grading is next.
- It is split into two plans: 4b, the grades core (this plan), and 4c, change requests and card slips.
- This ticket only migrates: regressions the move causes are fixed, existing flaws are left.
- The plan is committed with the phase.

**Branch:** `feature/modular-architecture` (4a committed as `5e2e3d3`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 0. Planning | 2026-11-08 17:15:56 | 17:21:09 | 5m 13s |
| 1. Shared pieces: shared lock, Classes and Learners APIs | 17:36:49 | 17:39:38 | 2m 49s |
| 2. Grading domain, repositories and hooks | 17:39:38 | 17:45:22 | 5m 44s |
| 3. Grading services, policy, use cases and queries | 17:45:22 | 17:54:32 | 9m 10s |
| 4. Grade screens | 17:54:32 | 18:03:03 | 8m 31s |
| 5. Baselines, docs, comments, deprecations | 18:03:03 | 18:24:47 | 21m 44s |
| 6. Verification | 18:24:47 | 19:37:27 | 1h 12m 40s |

## Global Constraints

- **Structure only.** These stay as today:
  - URLs, route names, HTTP methods and route requirements;
  - CSRF token ids (`grade-open-<period>`, `grade-close-<period>`, `grade-unlock-<period>`, `grade-unlock-class-<section>-<subject>-<period>`, `grade-lock-class-<section>-<subject>-<period>`, `grade-change-withdraw-<request>`, the lock and sheet forms);
  - form field names (`grade_sheet_form[...]`, `grade_lock_form[...]`);
  - flash, form-error and refusal messages, activity-log lines, status codes, redirects, access rules, and the card slip PDF download.

  Table and column names stay, `doctrine:migrations:diff` stays empty, and the route table matches HEAD.
- **Layer rules.**
  - Grading may use Core, Access (Domain, Api), Schools (Domain, Api), Classes (Domain, Api) and Learners (Domain, Api). Legacy may use Grading.
  - Peers on the same level (Attendance, Nutrition) reach Grading only through `Grading/Application/Api`; this phase needs none.
  - Grading never uses another module's repositories.
- **Bus and locks.**
  - Controllers extend `BusController`, and handlers refuse by throwing a `DomainException`.
  - Handlers that lock take the lock before any read, and read what a page query may have loaded fresh.
  - Every class keeps its class-level attributes when moved or split: `#[IsGranted(User::ROLE_TEACHER)]` and `#[Route('/teacher/grades')]` on the teacher's pages; `#[Route('/schools/{id}', requirements: [...])]` and `#[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]` on the school's.
- **New code:** `declare(strict_types=1)`, PHPStan level 9 with no baseline entries, phpcs PSR-12 (lines ≤ 120).
  - `src/Grading` keeps legacy files at its root until 4c, so config names the four layer folders `src/Grading/{Domain,Application,Infrastructure,Presentation}`: `<source>` in `phpunit.dist.xml`, `config/phpcs.xml`, and the Legacy collector in `config/deptrac.yaml`.
  - Coverage of those folders must be 100%.
- **Tests.**
  - Moved tests keep their assertions.
  - New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first), with every setup line under `// Initialize` and `// Execute` running the code under test. They use the existing helpers, and `createStub` unless the test sets expectations.
  - In functional tests, reload an entity through its repository after a request before changing it.
  - A test meant to guard a fix is broken once to watch it fail.
  - After moving a test, check its class name matches the file, its `__DIR__` paths still resolve, and it left the legacy suite.
- **Helper scripts.**
  - Check the scratchpad first: `move3d.py`, `set_namespace.py`, `replace_text.py`, `insert_after.py`, `int_keys.py`, `wrap_long.py`, `prune_baseline.py`, `baseline_check.py`, `compare_routes.py`, `to_lf.py`, `prune_uses.py`, `uncovered.py`, the live-check scripts.
  - Write new ones with the Write tool, writing LF. No inline heredocs, no `sed` for text with backslashes, and arguments starting with `//` go through a file. No `git add -N`.
  - Save the HEAD route dump and a baseline copy before the first code change.
- **Running checks.**
  - Tests are written per task and run once in Task 6, one command at a time, in Docker (`MSYS_NO_PATHCONV=1`).
  - Run the module's tests with coverage before the full `make test-feature`.
  - Read each check's output before recording it as passed.
  - Clear `reports/phpstan/.cache` before a PHPStan run whose result counts.
  - Long runs go to the background and are watched directly. Leftover servers, monitors and shells are stopped.
- **PHPStan baseline:** prune entries for deleted files and regenerate on a clear cache. The total must not grow, and no entry may name a module folder or a file not on `develop`.
- **Entity mapping:** Domain entities write column types as strings (`'date_immutable'`), never DBAL `Types` constants.
- **Live-browser check:** ask first and confirm headless or visible. Serve both sides through the `php -S` router, reset the dev data before each side and restore it after.
- **Comments and commit:** comments short and plain, with no Claude/Anthropic mention. One commit at the end after approval, through the Base hook, including this plan (`git add -f`).

## Review Focus

1. **Two changes at once.**
   - A sheet save holds the class lock plus a shared hold on the school's grade lock. Opening, closing, locking and unlocking hold the school's lock exclusively.
   - Either side refuses with "Another change is being saved. Please try again." while the other holds it.
   - The legacy change manager uses the same keys, so it stays excluded too.
   - A grade another request changed after the sheet page was read is read fresh under the lock, so the save isn't silently lost.
   - Pinned by handler tests: a held lock in each direction, and page query, then a change by SQL, then the save.
2. **Who may do what.**
   - Only a load's current teacher enters grades (not an administrator), and only for a gradable class.
   - The teacher, the adviser, the school's principal this year, and administrators view.
   - Only the principal locks; the principal and administrators open and close.
   - A POST to a sheet by someone who may only view gets 403 "You cannot enter grades for this class.".
   - A teacher who advises no section gets 403 "You do not advise a section this school year.".
   - An unknown load or period, another school's section or subject, or a period of another year gets 404.
   - Pinned by `GradePolicyTest` (from `GradeVoterTest`) and the moved controller tests.
3. **When grades may change.**
   - The four statuses and their refusals ("<Period> is locked by the principal.", "<Period> isn't open for grading.").
   - The 60–100 check refuses the whole sheet before anything changes.
   - Dropped and transferred-out rows are listed read-only.
   - Kinder, senior high, parent subjects and other school years aren't gradable.
   - Locking needs an opening or grades; unlocking clears class unlocks.
   - Pinned by the moved `GradeRulesTest` and `GradeRecorderTest`.
4. **Same numbers.** The grid's columns and parent averages, the school overview's progress and statuses, failing learners (general average rounding), the teacher's index counts, and the default period. Pinned by the moved `SectionGradeGridTest` and controller tests.
5. **Legacy keeps working.**
   - Change requests: send, approve, decline, withdraw, and the sheet's withdraw button.
   - Card slips: builder, comments, PDF from the adviser and the school page.
   - The principal dashboard's grade cards.
   - The three hooks: a graded period can't be deleted, a graded subject can't be deleted, a graded learner can't be unenrolled.
   - The demo fixtures.
   - Pinned by the legacy suite and the moved hook tests.

## Rulings in this plan

- **All grading entities move now**, including `GradeChangeRequest`, `GradeChangeLine`, `GradeChangeStatus` and `CardSlipComment`, with all six repositories. 4c moves only the change-request and card-slip services and screens. `ClassGradeStatus` becomes a Domain enum.
- **Legacy code kept until 4c.** `GradeLocks` and the legacy `App\Grading\GradingException` stay at the `src/Grading` root for `GradeChangeManager` and the card slip services. They use the same lock keys as the new handlers.
- **New exceptions.** The module's refusals are `App\Grading\Domain\Exception\GradingException` (code `GRADING_REFUSED`) and `GradingAccessDeniedException` (403), as in 4a. The busy text is Grading's own `BUSY` constant with today's words.
- **Shared lock in Core.** Core's `TransactionLockInterface` gains `acquireShared(string $resource, string $busyMessage): void`. It takes a shared hold (Symfony `acquireRead()`), released with the use case like `acquire()`. Taking a key the use case already holds, either way, does nothing.
- **Widened public APIs.** These mirror the repositories 1:1:
  - Classes' `SectionDirectoryInterface` gains `findSubject(int $id): ?Subject` and `subjectsOf(Section $section): list<Subject>` (`SubjectRepositoryInterface::findById`, `findForSection`).
  - A new Classes `TeachingLoadDirectoryInterface` offers `findLoad(int $id): ?TeachingLoad`, `loadsOf(Section $section): list<TeachingLoad>`, `teacherLoadsOf(User $teacher, SchoolYear $schoolYear): list<TeachingLoad>` and `loadOf(Section $section, Subject $subject): ?TeachingLoad`.
  - Learners' `SectionRosterInterface` gains `classList(Section $section): list<Enrollment>` and `transfersOutSince(Section $section, \DateTimeImmutable $since): list<TransferRequest>`.
- **Grading period lookup.** A period is found among its school year's `getGradingPeriods()` instead of `EntityManager::find()`. It is the same 404 when the period isn't that year's, so no Schools API method is needed.
- **Split controllers** (class-level attributes copied to each piece):
  - Change requests' two actions (`teacher_grades_change`, `teacher_grades_change_withdraw`) stay in a legacy `src/Controller/TeacherGradeChangeController.php`.
  - The card slip PDF route (`school_section_card_slips_pdf`) stays in a legacy `src/Controller/SchoolCardSlipController.php`.
  - The legacy `GradeVoter` keeps only `CHANGE_WITHDRAW` and `CHANGE_DECIDE`; the legacy controllers ask `GradePolicy` for `VIEW` and `EDIT`.
- **Grade repository.** It gains `findFreshForEnrollments()` (same arguments as `findForEnrollments()`, with `Query::HINT_REFRESH`), which the sheet save uses under its locks.
- **Page data.** Queries pass `SheetLearner`, `GridColumn`, `ClassProgress`, `FailingLearner`, the grid arrays, entities and statuses through unchanged, so the templates keep reading them. The change-request templates stay in `templates/grades/` until 4c, and module templates include them by that path.
- **Test suite listing.** The legacy suite lists the remaining `tests/Grading` file (`GradeChangeManagerTest.php`) by name, not the folder, so module tests aren't added twice.

---

### Task 1: Shared pieces: shared lock, Classes and Learners APIs

**Files:**
- Modify: `src/Core/Domain/Service/Lock/TransactionLockInterface.php`, `src/Core/Infrastructure/Lock/TransactionLock.php`
- Modify: `src/Classes/Application/Api/SectionDirectory{Interface,}.php`
- Create: `src/Classes/Application/Api/TeachingLoadDirectory{Interface,}.php`
- Modify: `src/Learners/Application/Api/SectionRoster{Interface,}.php`
- Test: `tests/Core/Integration/Infrastructure/Lock/TransactionLockTest.php`:
  - two shared holds coexist;
  - a shared hold refuses an exclusive one with the busy message, and the reverse;
  - a held key is not taken twice;
  - outside a use case it throws.
- Test: the Classes API tests (each new method against its repository's answer).
- Test: `tests/Learners/Integration/Api/SectionRosterTest.php` (`classList`, `transfersOutSince`).

**Interfaces — Produces:** the signatures in the rulings above.

- [ ] **Step 1:** Save the HEAD route dump and a baseline copy (scratchpad).
- [ ] **Step 2:** Write the tests above.
- [ ] **Step 3:** Implement.
- [ ] **Step 4: Setup checks:** `cache:clear`, `lint:container`.

### Task 2: Grading domain, repositories and hooks

**Files:**
- Move entities: `src/Entity/{Grade,GradeChangeRequest,GradeChangeLine,GradeChangeStatus,GradeLock,GradeOpening,GradeUnlock,CardSlipComment}.php` and `src/Grading/ClassGradeStatus.php` → `src/Grading/Domain/Model/`.
- Move repositories: `src/Repository/{Grade,GradeChangeRequest,GradeLock,GradeOpening,GradeUnlock,CardSlipComment}Repository.php` → `src/Grading/Infrastructure/Repository/Doctrine*Repository.php`.
  - Their interfaces go in `src/Grading/Domain/Model/Repository/`: today's public methods, plus `add`/`remove` where callers persist or remove, plus `GradeRepositoryInterface::findFreshForEnrollments()`.
- Create: `src/Grading/Domain/Exception/{GradingException,GradingAccessDeniedException}.php`.
- Move hooks:
  - `src/Service/SchoolsHooks/CurrentGradingPeriodUsage.php` → `src/Grading/Infrastructure/Schools/`;
  - `src/Service/ClassesHooks/CurrentSubjectRecords.php` → `src/Grading/Infrastructure/Classes/`;
  - `src/Service/LearnersHooks/CurrentLearnerGrades.php` → `src/Grading/Infrastructure/Learners/`.

  Delete the emptied `src/Service/*Hooks` folders.
- Modify config:
  - `config/packages/doctrine.yaml` (Grading mapping) and `config/packages/twig.yaml` (`@Grading`).
  - `config/deptrac.yaml`: the `Grading_{Domain,Application,Api,Infrastructure,Presentation}` layers and rules. The Legacy collector excludes `Grading\\(?:Domain|Application|Infrastructure|Presentation)\\`, and Legacy may use Grading.
  - `config/phpcs.xml` and `phpunit.dist.xml` (the four folders; the legacy suite names `tests/Grading/GradeChangeManagerTest.php`).
- Modify: legacy users of the moved classes (scripted import rewrite) and `tests/SchoolFixturesTrait.php`.
- Move tests:
  - `tests/Entity/{GradeEntitiesTest,GradeChangeRequestTest}.php` → `tests/Grading/Unit/Domain/`;
  - `tests/Repository/{GradeChangeRequestRepositoryTest,GradeOpeningRepositoryTest}.php` → `tests/Grading/Integration/Infrastructure/`;
  - `tests/Schools/Integration/Infrastructure/CurrentGradingPeriodUsageTest.php`, `ClassesHooksTest::testCountsTheGradesAndChangeRequestsOfASubject` and `LearnersHooksTest::testCountsTheGradesOfAnEnrollment` → `tests/Grading/Integration/Infrastructure/GradingHooksTest.php`.
- Test (new): `findFreshForEnrollments()` reads a value another connection changed after it was loaded.

- [ ] **Step 1:** Move and write the tests above.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `cache:clear`, `lint:container`, `doctrine:schema:validate`, `doctrine:migrations:diff` empty.

### Task 3: Grading services, policy, use cases and queries

**Files:**
- Move to `src/Grading/Application/Service/`:
  - `src/Grading/{GradeRules,SectionGradeGrid,SchoolGradeOverview,FailingLearners,GeneralAverage,SheetLearner,GridColumn,ClassProgress,FailingLearner}.php`. They read Classes and Learners through their APIs.
  - `src/Grading/GradeRecorder.php`: no lock, no flush, refusals as the Domain `GradingException`, `classLabel()` kept.
- Create in `src/Grading/Application/Service/`:
  - `GradePolicy.php` (`EDIT`, `VIEW`, `VIEW_SECTION`, `LOCK`, `OPEN`, with today's voter rules);
  - `GradingScope.php`: the load and period ("No such class or grading period."), the current year ("No current school year."), a period of the year ("No such grading period."), a section or class of the school (404), the adviser's section (403, today's text).
- Commands (`UseCase/Command/`) and handlers:
  - `SaveGradeSheetCommand(actorId, loadId, periodId, values)`: takes `grades-<section>-<subject>`, then the shared `grade-lock-<school>`; reads grades through `findFreshForEnrollments()`; its Result data is the graded count.
  - `OpenGradingPeriodCommand`, `CloseGradingPeriodCommand`, `LockGradingPeriodCommand`, `UnlockGradingPeriodCommand` (actorId, schoolId, periodId).
  - `UnlockClassGradesCommand`, `LockClassGradesCommand` (actorId, schoolId, sectionId, subjectId, periodId).
  - Each takes `grade-lock-<school>` first, and each checks `GradePolicy`.
- Queries (`Query/Command/`) and handlers:
  - `GetTeacherGradesCommand`: the index.
  - `GetGradeSheetCommand`: everything `sheet.html.twig` reads, plus `canEdit`.
  - `GetSchoolGradesCommand`: overview, lock, opening, `canLock`, `canOpen`, pending changes.
  - `GetFailingLearnersCommand`.
  - `GetSectionGradesCommand`: the school's section grid.
  - `GetClassGradesCommand`: the adviser's grid.
- Modify: `src/Grading/GradeChangeManager.php` and `src/CardSlip/*` (legacy) use the moved services and repository interfaces. `src/DataFixtures/DemoFixtures.php` uses the moved recorder and flushes after each sheet.
- Move tests: `tests/Grading/{GradeRecorderTest,GradeRulesTest,SectionGradeGridTest}.php` → `tests/Grading/Integration/Application/`. The recorder tests go through a saving helper; lock and stale cases go through the bus.
- Test (new):
  - `GradePolicyTest`: the voter's cases for the moved attributes.
  - `GradingHandlersTest`:
    - each use case through the bus;
    - a held class lock and a held school lock refuse a save;
    - a save's shared hold refuses a period lock;
    - page query, then a change by SQL, then the save keeps the posted value;
    - 403 and 404 refusals;
    - each query's shape.

- [ ] **Step 1:** Move and write the tests above.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `lint:container`.

### Task 4: Grade screens

**Files:**
- Move to `src/Grading/Presentation/Controller/`, extending `BusController` with the same routes and class-level attributes:
  - `src/Controller/TeacherGradeController.php`: `index`, `sheet`. `change` and `withdraw` go to the legacy `TeacherGradeChangeController`, with the same class attributes.
  - `src/Controller/SchoolGradeController.php`: everything except `cardSlips`, which goes to the legacy `SchoolCardSlipController`, with the same class attributes.
  - `src/Controller/TeacherClassGradesController.php`.
- Move forms: `src/Form/{GradeSheetFormType,GradeSheet,GradeLockFormType}.php` → `src/Grading/Presentation/Form/`, keeping the same block prefixes.
- Move templates: `templates/grades/{index,sheet,school,school_section,failing,class,_grid}.html.twig` → `src/Grading/Presentation/templates/grades/`, referenced as `@Grading/grades/...`. The change-request templates (`_change_request_macros`, `_change_requests`, `_changes_waiting_card`, `change`, `changes`) stay where they are.
- Trim `src/Security/Voter/GradeVoter.php` to the change-request attributes.
- Move tests:
  - `tests/Controller/{TeacherGradeControllerTest,SchoolGradeControllerTest}.php` → `tests/Grading/Functional/`. The change-request cases stay in a legacy `tests/Controller/TeacherGradeChangeControllerTest.php`.
  - The adviser grade-page cases of `tests/Learners/Functional/TeacherClassControllerTest.php` → `tests/Grading/Functional/TeacherClassGradesControllerTest.php`.
  - `tests/Security/GradeVoterTest.php` keeps the change-request cases.
- Test (new):
  - a save while the class is being changed answers with the busy message;
  - a former teacher gets 403 on every teacher grade route.

- [ ] **Step 1:** Move and write the tests.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `lint:container`, `lint:twig`, route table identical to HEAD.

### Task 5: Baselines, docs, comments, deprecations

- [ ] **Step 1: Static checks.**
  - phpcbf, then phpcs clean.
  - LF line endings on every touched file.
  - PHPStan on the module folders (clear cache), then regenerate the baseline.
  - deptrac 0; ESLint and Stylelint clean.
- [ ] **Step 2: Docs.** Update `CLAUDE.md`:
  - the Grading module (entities, services, its locks, what stays legacy until 4c);
  - Core's `acquireShared()`;
  - the widened Classes and Learners APIs;
  - the hooks' new homes.
- [ ] **Step 3: Comment sweep and deprecation check** (`debug:container --deprecations`) over everything touched.

### Task 6: Verification

- [ ] **Step 1: Scoped run** of the affected tests: `tests/Grading`, `tests/CardSlip`, `tests/Core` lock, the Classes and Learners API and hook tests, change-request, dashboard and fixture tests. Fix, then re-run only the failures.
- [ ] **Step 2: Coverage.** Run the module's tests with coverage; nothing in the four Grading folders is left uncovered.
- [ ] **Step 3: Full runs on the final code, one at a time:**
  - `make test all NO_PARALLEL=1` (fresh PHPStan);
  - `make test-feature`;
  - `make regression-test`;
  - schema valid and diff empty; route table identical to HEAD.
- [ ] **Step 4: Fresh whole-change review** (most capable model, read-only). Fix Critical and Important findings (and anything a user would see) with a failing test first, then repeat the full runs.
- [ ] **Step 5: Ask the owner before the live-browser check, and whether headless or visible.** If headless, compare HEAD and the branch on the same dev data, reset before each side, then restore the dev data:
  - the teacher's grades index and a sheet (open, closed, locked; saving; an out-of-range grade);
  - a change request (send, withdraw);
  - the adviser's class grades;
  - the school overview: open, close, lock, unlock, unlock a class, lock it again;
  - failing learners and a section's grades;
  - the principal's dashboard;
  - card slips (page and PDF).
- [ ] **Step 6: Report** and propose the commit (code and this plan); commit only after approval:

```
Move grades into the Grading module

Create the Grading module with the grading entities and repositories,
grade sheets, opening, closing and locking of grading periods, class
unlocks, and the teacher's, adviser's and school's grade pages, with
every change through the bus. Core locks can be shared, and the
Classes and Learners APIs offer the subjects, teaching loads and class
lists grading reads. Change requests and card slips use the moved code
and keep their screens for now.
```
