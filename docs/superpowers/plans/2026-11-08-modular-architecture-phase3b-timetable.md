# Modular architecture, Phase 3b (Classes: timetable and teacher load) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the section timetable (page, JSON endpoints, Stimulus controller) and the teacher-load pages into the `Classes` module, every action through the Core bus, with nothing a user would notice changing. This finishes the Classes module.

**Architecture:** Same patterns as Phase 3a. `TimetableManager` and `TimetableQuery` become Classes Application services; the manager no longer locks, flushes or resets the entity manager — its use case handlers take the `school-timetable-<id>` lock first and the bus transaction saves. `TimetableException` becomes a Classes `DomainException` that still carries the clash list. The JSON endpoints keep their shapes; after a write, the controller asks the query bus for the fresh state (it must read what was committed). The legacy dashboards (principal "now", teacher "now", student timetable) keep using `TimetableQuery` from Classes until Dashboards moves. `timetable_controller.js` moves into the module with Vitest tests (owner, 2026-11-08).

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Twig, Stimulus, PHPUnit 13, Vitest 5 + jsdom, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row `Classes`, sections 5, 6, 8). Owner decisions (2026-11-08): Classes in two plans (3a committed as `ed401f7`); Vitest tests for the Classes Stimulus controllers; this ticket only migrates (regressions the move causes are fixed, existing flaws are left).

**Branch:** `feature/modular-architecture` (Phase 3a committed as `ed401f7`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 0. Planning | 2026-11-08 04:08:41 | 04:10:25 | 1m 44s |
| 1. Timetable services | 04:25:17 | 04:30:55 | 5m 38s |
| 2. Timetable page and JSON endpoints | 04:30:55 | 04:36:35 | 5m 40s |
| 3. Timetable Stimulus controller | 04:36:35 | 04:39:52 | 3m 17s |
| 4. Teacher load | 04:39:52 | 04:42:05 | 2m 13s |
| 5. Baselines, docs, comments, deprecations | 04:42:05 | 05:05:03 | 22m 58s |
| 6. Verification (runs, coverage, review fix, live check) | 05:05:03 | 08:24:34 | 3h 19m 31s |

## Global Constraints

- Structure only: URLs, route names (`school_timetable`, `school_timetable_state`, `school_timetable_load`, `school_timetable_plot`, `school_timetable_remove`, `school_teacher_load`, `teacher_load`), HTTP methods, the CSRF header `X-CSRF-Token` with token id `timetable-<sectionId>`, JSON request and response shapes (`busy` always an object; refusals are 422 `{message, clashes?}`), refusal messages, activity-log lines, the `term` query handling and access rules stay as today. Table and column names stay; `doctrine:migrations:diff` stays empty; the route table matches HEAD.
- Classes may use Core, Access (Domain, Api), Billing (Domain, Api) and Schools (Domain, Api). Legacy may use Classes.
- Controllers extend `BusController`; handlers refuse by throwing a Classes/Core `DomainException`; handlers that lock take the lock before any read (`ClassPeriodManager::LOCK_PREFIX`, `ClassPeriodManager::BUSY`). Templates move to `src/Classes/Presentation/templates` with their relative paths kept.
- The Stimulus controller moves to `src/Classes/Presentation/assets/controllers/timetable_controller.js` with `import.meta.stimulusIdentifier = 'timetable'`; 100% Vitest coverage, tests in `tests/Classes/Javascript/`.
- New code: `declare(strict_types=1)`, PHPStan level 9 with no baseline entries, phpcs PSR-12 (lines ≤ 120), 100% PHP coverage of the moved modules.
- Moved tests keep their assertions. New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first) with every setup line under `// Initialize`, the existing helpers, and `createStub` unless the test sets expectations.
- Helper scripts go to the scratchpad via the Write tool (LF line endings); no inline heredocs; no `sed` for text with backslashes. Save the HEAD route dump before the first code change.
- Tests are written per task and run once in Task 6, one command at a time, in Docker (`MSYS_NO_PATHCONV=1` for container paths). PHPStan keeps its 4-worker cap. Long runs go to the background and are watched directly (output, container memory, MySQL status); leftover servers, monitors and shells are stopped when done.
- PHPStan baseline: delete `reports/phpstan/.cache`, prune entries for deleted files, regenerate; the total must not grow and no entry may name a module folder or a file not on `develop`.
- Live-browser check: ask first and confirm headless or visible; check the saved HTML files aren't empty before trusting the comparison. Comments short and plain; no Claude/Anthropic mention. One commit at the end after approval, through the Base hook.

## Review Focus

1. **Two saves at once.** Two requests plotting into the same slot or teacher never both save: each write waits for `school-timetable-<id>` ("Another change is being saved. Please try again." as 422 JSON), and a unique-constraint clash on commit also answers with that message instead of an error page. Pinned by the moved `TimetableManagerTest` race cases and a new controller case (Task 2).
2. **Refusals and clashes.** Every refusal keeps its text (not a subject of the section, has components, break period, no teacher yet, no meetings per week, all meetings used, section slot taken, teacher busy — with every clash listed in `clashes` when changing a teacher, clearing a plotted load, plan blocked). Pinned by the moved `TimetableManagerTest` and `SchoolTimetableControllerTest` (Tasks 1–2).
3. **Terms.** A section or teacher with semester subjects shows term 1 by default and switches with `?term=`; an unknown term falls back to the first; K–10 shows the whole year. Pinned by the moved controller and query tests (Tasks 2 and 4).
4. **What may be loaded and seen.** Only teachers of the school this school year can be picked (404 otherwise); a section, subject, period or entry of another school or year is a 404; a principal sees only their school's teacher loads; a teacher sees their own load page even without a school. Pinned by the moved controller tests (Tasks 2 and 4).
5. **The page keeps working in the browser.** The JS grid draws the state, switches terms, sets teachers, plots by drag and drop or the dialog, removes, shows refusals with their clash list and keeps working after an error. Pinned by the Vitest tests (Task 3) and the live check.

## Rulings in this plan

- `TimetableException` moves to `App\Classes\Domain\Exception` with its class name and `getClashes()`, and becomes a Core `DomainException` (code `TIMETABLE_REFUSED`).
- A write endpoint makes two bus calls: the use case, then `GetSectionTimetableCommand` for the response body — the state must be read after the use case's transaction has committed.
- A `UniqueConstraintViolationException` coming back from the bus (another request saved the same slot first) is answered with the busy message as 422 JSON, as the manager did with its own catch; the Core bus already resets the entity manager.
- `TeachingLoadRepositoryInterface` and `ScheduleEntryRepositoryInterface` get `add()`/`remove()` back (the manager needs them now).
- The request DTOs (`TimetablePlotInput`, `TimetableTeacherInput`) move to `src/Classes/Presentation/Input/` and stay `#[MapRequestPayload]` inputs; the controller turns them into commands.
- `DashboardNowTest` and the legacy dashboards stay legacy; only their imports change.

---

### Task 1: Timetable services

**Files:**
- Move: `src/Service/TimetableManager.php` → `src/Classes/Application/Service/Timetable/TimetableManager.php`; `src/Service/TimetableQuery.php` → `src/Classes/Application/Service/Timetable/TimetableQuery.php`; `src/Service/TimetableException.php` → `src/Classes/Domain/Exception/TimetableException.php`
- Modify: `TeachingLoadRepositoryInterface`/`ScheduleEntryRepositoryInterface` and their Doctrine classes (`add`, `remove`); legacy users of the moved classes (`Principal/DashboardController`, `StudentController`, `TeacherController`, `DemoFixtures` — which flushes after each `setTeacher()`/`plot()`)
- Move tests: `tests/Service/{TimetableManagerTest,TimetableQueryTest,TimetableNowTest}.php` → `tests/Classes/Integration/Application/`; `tests/Controller/DashboardNowTest.php` gets the new imports

**Interfaces:**
- Produces:
  - `TimetableManager(TeachingLoadRepositoryInterface, ScheduleEntryRepositoryInterface, SubjectRepositoryInterface, ClassPeriodManager, SchoolDirectoryInterface, ActivityLoggerInterface)`: `setTeacher(Section, Subject, ?User): ?TeachingLoad`, `plot(Section, Subject, Weekday, ClassPeriod): ScheduleEntry`, `remove(ScheduleEntry): void` — today's checks, messages and log lines, no lock, no flush; slot labels from `ClassPeriodManager::label()`.
  - `TimetableQuery`: today's public methods (`termsFor`, `sectionState`, `teacherWeek`, `now`, `nowForTeacher`) and return shapes.
  - `TimetableException(string $message, list<string> $clashes = [])` code `TIMETABLE_REFUSED`, `getClashes(): list<string>`.

- [ ] **Step 1: Save the HEAD route dump** (scratchpad).
- [ ] **Step 2: Move the tests**; tests that relied on the manager's own lock or flush call it the way a use case does (a flushing test helper, as `SavingSubjectManager` in 3a) or go through the bus; the lock and race cases move to the handler tests in Task 2.
- [ ] **Step 3: Move and rewrite** the three classes; repoint legacy users.
- [ ] **Step 4: Setup checks:** `cache:clear`, `lint:container`, `doctrine:schema:validate`.

### Task 2: Timetable page and JSON endpoints

**Files:**
- Create: `src/Classes/Application/UseCase/Command/{SetTeachingLoadCommand,PlotClassCommand,RemoveClassCommand}.php` and handlers, `src/Classes/Application/Query/Command/GetSectionTimetableCommand.php` and handler, `src/Classes/Presentation/Controller/SchoolTimetableController.php`
- Move: `src/Dto/{TimetablePlotInput,TimetableTeacherInput}.php` → `src/Classes/Presentation/Input/`; `templates/school_timetable/show.html.twig` → `src/Classes/Presentation/templates/school_timetable/`
- Delete: `src/Controller/SchoolTimetableController.php`, the empty `src/Dto`
- Move tests: `tests/Controller/SchoolTimetableControllerTest.php` → `tests/Classes/Functional/`
- Test (new): `tests/Classes/Integration/Application/TimetableHandlersTest.php` (lock wait, unique-clash answer, every not-found), new controller cases

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `SetTeachingLoadCommand(int $schoolId, int $sectionId, int $subjectId, ?int $teacherId)`, `PlotClassCommand(int $schoolId, int $sectionId, int $subjectId, int $day, int $periodId)`, `RemoveClassCommand(int $schoolId, int $sectionId, int $entryId)` — each handler takes the lock first; unknown section/subject/period/teacher/entry → not-found exceptions with today's 404 messages ("This section is not in the school this school year.", "This subject is not in the school this school year.", "This class period is not in the school this school year.", "This teacher is not in the school this school year.", "This class is not in the section.").
  - `GetSectionTimetableCommand(int $schoolId, int $sectionId, ?int $term)` → `['section' => SectionData, 'state' => <TimetableQuery::sectionState()>]` with today's term fallback.
  - Controller: same routes, attributes and responses; `state` responses keep `busy` as an object.
- [ ] **Step 1: Move the tests** and add the handler tests and controller cases (busy and unique clash → 422 with the busy message; each 404).
- [ ] **Step 2: Implement.**
- [ ] **Step 3: Setup checks:** `lint:container`, `lint:twig`, route table identical to HEAD.

### Task 3: Timetable Stimulus controller

**Files:**
- Move: `assets/controllers/timetable_controller.js` → `src/Classes/Presentation/assets/controllers/` (identifier line added, nothing else)
- Test (new): `tests/Classes/Javascript/timetable_controller.test.js` (jsdom, `fetch` stubbed)

- [ ] **Step 1: Write the Vitest tests**: connect loads and draws terms, cards and grid; switching terms; setting and clearing a teacher (PUT with the CSRF header); plotting by drop and by the dialog (POST); removing (DELETE); a 422 refusal shows its message and clash list and the grid redraws; other failures show a generic message; dragging marks busy and blocked slots; the dialog opens, lists choices and closes; disconnect cleans up — until every line and branch is covered.
- [ ] **Step 2: Move the controller.**

### Task 4: Teacher load

**Files:**
- Create: `src/Classes/Application/Query/Command/GetTeacherLoadCommand.php` and handler, `src/Classes/Presentation/Controller/TeacherLoadController.php`
- Move: `templates/teacher_load/show.html.twig` → `src/Classes/Presentation/templates/teacher_load/`
- Delete: `src/Controller/TeacherLoadController.php`
- Move tests: `tests/Controller/TeacherLoadControllerTest.php` → `tests/Classes/Functional/`
- Test (new): handler cases in `TimetableHandlersTest`

**Interfaces:**
- Consumes: Task 1.
- Produces: `GetTeacherLoadCommand(int $teacherId, ?int $schoolId, ?int $term)` — with a school id the teacher must teach there this school year (404 "This teacher is not in the school this school year."); without one, the teacher's own school (may be none) → `['teacher' => {id, fullName}, 'school' => ?{id, name}, 'schoolYear' => ?{name}, 'week' => ?<teacherWeek>, 'term' => int, 'hasSemesters' => bool]` with today's term rules.
- [ ] **Step 1: Move the test** and add handler cases; **Step 2: Implement**; **Step 3: Setup checks** (`lint:twig`, routes identical).

### Task 5: Baselines, docs, comments, deprecations

- [ ] **Step 1:** phpcbf, then phpcs clean; PHPStan on the module folders, then baseline regeneration; deptrac 0; ESLint and Stylelint clean.
- [ ] **Step 2: Docs.** `CLAUDE.md`: the timetable and teacher load now in Classes (`TimetableManager`, `TimetableQuery`, the JSON endpoints, the lock taken by the handlers); the legacy dashboards use `TimetableQuery` from Classes.
- [ ] **Step 3: Comment sweep and deprecation check** (`debug:container --deprecations`, ESLint) over everything touched.

### Task 6: Verification

- [ ] **Step 1: Scoped run** of the PHPUnit suites (`unit,integration,functional,legacy`) with `--display-phpunit-notices`, then `make test vitest`; fix, re-run only failures.
- [ ] **Step 2: Coverage** → nothing uncovered (PHP and JS).
- [ ] **Step 3: Full runs on the final code, one at a time:** `make test all NO_PARALLEL=1`, `make test-feature`, `make regression-test`; schema valid, diff empty; route table identical to HEAD.
- [ ] **Step 4: Fresh whole-change review** (most capable model, read-only); fix Critical/Important (and anything a user would see) with a failing test first; full runs again.
- [ ] **Step 5: Ask the owner before the live-browser check, and whether headless or visible.** If headless: HEAD vs branch on the same dev data (data reset between sides): the timetable page, the state JSON, setting and clearing a teacher, plotting (drop and dialog), a refused plot with clashes, removing, the term switch on a Senior High section if the data has one, the principal's and the teacher's load pages; then restore the dev data.
- [ ] **Step 6: Report** and propose the commit; commit only after approval:

```
Move the timetable and teacher load into the Classes module

Move the section timetable (page, JSON endpoints and its Stimulus
controller) and the teacher load pages into the Classes module, with
every change through the bus under the school's timetable lock. The
dashboards read the timetable from Classes. Pages, responses and URLs
are unchanged.
```
