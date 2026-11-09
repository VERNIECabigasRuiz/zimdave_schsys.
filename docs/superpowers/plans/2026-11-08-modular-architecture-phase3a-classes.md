# Modular architecture, Phase 3a (Classes: structure, sections, subjects, class periods) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create the `Classes` module with all of its entities and repositories, and move the section, subject (school and Individual-plan teacher) and class period screens into it, every action through the Core bus, with nothing a user would notice changing.

**Architecture:** Same patterns as Phases 2d/2e. The seven entities and five repositories move to `src/Classes/Domain` (interfaces) and `src/Classes/Infrastructure/Repository` (Doctrine); legacy code (timetable, learners, attendance, grades…) keeps working through the repository interfaces. `SectionManager`, `SubjectManager` and `ClassPeriodManager` become Classes Application services that no longer flush or read Doctrine's unit of work: they check a change before applying it. Learner and grade counts reach Classes through Classes hooks implemented by legacy classes; school, school-year and teacher lookups through a new Schools public API. Entity validation (Assert attributes, callbacks, `UniqueSection`, `UniqueSubject`, `NoOverlappingPeriod`) moves to the command DTOs and form-level constraints in Classes Presentation. The three Stimulus controllers move into the module and get Vitest tests (owner, 2026-11-08). Phase 3b moves the timetable and teacher load.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Twig, Stimulus, PHPUnit 13, Vitest 5 + jsdom, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row `Classes`, sections 5, 6, 8, 10). Owner decisions (2026-11-08): Classes in two plans (3a here, 3b timetable and teacher load); Vitest tests for the Classes Stimulus controllers; this ticket only migrates (regressions the move causes are fixed, existing flaws are left).

**Branch:** `feature/modular-architecture` (Phase 2e committed as `f658d8d`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 0. Planning | 2026-11-08 00:48:50 | 00:55:50 | 7m 00s |
| 1. Classes domain, repositories, hooks, Schools API | 01:03:01 | 01:13:53 | 10m 52s |
| 2. Sections | 01:13:53 | 01:26:25 | 12m 32s |
| 3. Subjects | 01:26:25 | 01:40:28 | 14m 03s |
| 4. Class periods | 01:40:28 | 01:45:36 | 5m 08s |
| 5. Baselines, docs, comments, deprecations | 01:45:36 | 02:02:14 | 16m 38s |
| 6. Verification (runs, coverage, review fix, live check) | 02:02:14 | 03:32:29 | 1h 30m 15s |

## Global Constraints

- Structure only: URLs, route names, form field names (`section_form[...]`, `subject_form[...]`, `class_period_form[...]`), flash messages, error texts and where they show (field, page, status 422), activity-log lines, CSRF token ids (`section-<id>`, `bring-back-sections`, `subject-<id>`, `deped-subjects`, `bring-back-subjects`, `class-period-<id>`), lock keys and access rules stay as today. Table, column and constraint names stay; `doctrine:migrations:diff` stays empty; the route table matches HEAD.
- Classes may use Core, Access (Domain, Api), Billing (Domain, Api) and Schools (Domain, Api). Enrollments, transfers, grades and grade change requests reach Classes only through its hook interfaces. Legacy code reaches Classes repositories through their interfaces only (no `find()`/`findBy()` on them; add named methods).
- Controllers extend `BusController`, one bus call per action except where a ruling below says otherwise; handlers refuse by throwing a Classes/Core `DomainException`; handlers that lock take the lock before any read; forms bind to the bus Command objects. Templates move to `src/Classes/Presentation/templates` with their relative paths kept (`@Classes/school_sections/index.html.twig`…).
- Stimulus controllers move to `src/Classes/Presentation/assets/controllers/` and set `import.meta.stimulusIdentifier` to today's identifier (`section-form`, `subject-form`); 100% Vitest coverage, tests in `tests/Classes/Javascript/`.
- New code: `declare(strict_types=1)`, PHPStan level 9 with no baseline entries, phpcs PSR-12 (lines ≤ 120), 100% PHP coverage of `src/Core`, `src/Access`, `src/Billing`, `src/Schools`, `src/Classes` (add `src/Classes` to `<source>` in `phpunit.dist.xml`).
- Moved tests keep their assertions. New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first) with every setup line under `// Initialize`, the existing helpers (`AdminTestCase`, `SchoolFixturesTrait`), and `createStub` unless the test sets expectations.
- Helper scripts are written with the Write tool to the scratchpad and write files with `newline='\n'`; no inline heredocs. Before the first code change, save the route dump of HEAD.
- Tests are written per task and run once in Task 6, one command at a time, in Docker (`MSYS_NO_PATHCONV=1` for `docker exec` with container paths). PHPStan runs with the 4-worker cap. Long runs go to the background and are watched directly (output tail, container memory, MySQL status), never by waiting on the shell alone; stop leftover servers, monitors and shells when done.
- PHPStan baseline: delete `reports/phpstan/.cache`, prune entries for deleted files, regenerate; the total must not grow and no entry may name a module folder or a file not on `develop`.
- Live-browser check: ask first and confirm headless or visible. Comments short and plain; no Claude/Anthropic mention. One commit at the end after approval, through the Base hook.

## Review Focus

1. **Edits refused for things in use.** A section with loads keeps its grade, track and strand ("<section> has a timetable. Remove its classes and teachers first."); a loaded subject keeps its grade, semester and parent and gets no components ("<subject> is loaded in <n> section(s). Clear its teachers first."); a period with classes can't become a break or be deleted ("<label> has classes in <n> section(s). Remove them first."). After a refusal nothing of the edit is saved and the list shows the stored values. Pinned by the moved `InUseProtectionTest` (Tasks 2–4).
2. **Deleting.** Sections with learners or pending transfers, subjects with loads, grades or grade change requests are refused with today's messages; deleting a subject deletes its components (log "… with <n> components"). Pinned by `InUseProtectionTest` and the moved controller tests.
3. **Validation messages and where they show.** Every Assert and callback message keeps its field (e.g. "Choose the track of this Senior High section." under `track`, "This time overlaps Recess (10:00–10:20)." under `startsAt`, "Enter the break name." under `breakLabel`, the duplicate name/code/adviser messages) and the form re-renders with 422 where it does today. Pinned by the moved controller tests plus new cases for each message (Tasks 2–4).
4. **Who may use the subject pages.** Admin and the school's principal use `/schools/{id}/subjects`; an Individual-plan teacher uses `/teacher/subjects` for their advisory's grade only; a teacher on a plan with a principal gets 403 "Your principal sets up the subjects of your school." before the CSRF check; a teacher without an advisory sees the "need a section" page. Pinned by the moved `TeacherSubjectsTest` (Task 3).
5. **Class period order and labels.** Periods stay numbered in time order after add, edit and delete ("Added Period 3.", log "Added class period Period 3"), breaks unnumbered; changes wait for the `school-timetable-<id>` lock ("Another change is being saved. Please try again."). Pinned by the moved `SchoolClassPeriodControllerTest` and `InUseProtectionTest::testPeriodWaitsForTimetableChanges` (Task 4).

## Rulings in this plan

- `InUseException` moves to `App\Classes\Domain\Exception` and becomes a Core `DomainException` (code `IN_USE`); Billing's `PlanInUseException` is unrelated and stays.
- Services check before they change: `update…()` methods take the new values and refuse before applying them, so the `refresh()`/original-data tricks go away and a refused use case rolls back.
- Class period labels ("Period 3") are computed from the in-memory day (the school's periods plus the new one, minus the deleted one, in time order), so no mid-use-case flush is needed. `ClassPeriodRepositoryInterface::numbering()`/`label()` stay for legacy callers.
- The teacher subject pages check the plan (403) before the CSRF token, as today: their write actions first ask `GetSubjectScopeCommand`, then send the use case (two bus calls).
- `SchoolVoter::MANAGE` takes its value from the new `SchoolDirectoryInterface::MANAGE` constant, so Classes controllers don't depend on Schools Presentation.
- `teacher_section_new` (Individual-plan teacher creates their section) moves to `App\Classes\Presentation\Controller\TeacherSectionController`; the rest of `TeacherController` stays legacy.
- `DepEdSubjects` moves to `App\Classes\Domain\Service\Subject\DepEdSubjects` (pure data); `TakenSubjects` to the same folder.
- `CurrentTeacherDeparture` (Schools hook) moves to `src/Classes/Infrastructure/Schools/` since Classes now owns sections and loads.

---

### Task 1: Classes domain, repositories, hooks, Schools API

**Files:**
- Move: `src/Entity/{Section,Subject,ClassPeriod,TeachingLoad,ScheduleEntry,Weekday,ShsTrack}.php` → `src/Classes/Domain/Model/` (Doctrine attributes kept; every `Assert`, `#[Assert\Callback]` method and `#[UniqueSection]`/`#[UniqueSubject]`/`#[NoOverlappingPeriod]` removed — they reappear in Tasks 2–4)
- Move: `src/Repository/{Section,Subject,ClassPeriod,TeachingLoad,ScheduleEntry}Repository.php` → `src/Classes/Infrastructure/Repository/Doctrine<Name>Repository.php` implementing `src/Classes/Domain/Model/Repository/<Name>RepositoryInterface.php`
- Move: `src/Service/InUseException.php` → `src/Classes/Domain/Exception/`; `src/Data/DepEdSubjects.php` and `src/Service/TakenSubjects.php` → `src/Classes/Domain/Service/Subject/`; `src/Service/SchoolsHooks/CurrentTeacherDeparture.php` → `src/Classes/Infrastructure/Schools/`
- Create: `src/Classes/Domain/Service/Usage/{SectionLearnersInterface,SubjectRecordsInterface}.php`, legacy `src/Service/ClassesHooks/{CurrentSectionLearners,CurrentSubjectRecords}.php`; `src/Schools/Application/Api/{SchoolDirectoryInterface,SchoolDirectory}.php`
- Modify: `config/packages/doctrine.yaml` (Classes mapping), `config/deptrac.yaml` (Classes layers, `Schools_Api`), `config/services.yaml` if repositories need aliases, `phpunit.dist.xml` (`src/Classes` in `<source>`), `src/Schools/Presentation/Security/SchoolVoter.php`, every legacy file naming a moved class (scratchpad FQCN rewrite as in 2e), `src/DataFixtures/DemoFixtures.php`
- Move tests: `tests/Data/DepEdSubjectsTest.php` → `tests/Classes/Unit/Domain/`; the loads/advisory case of `tests/Schools/Integration/Infrastructure/SchoolsHooksTest.php` → `tests/Classes/Integration/Infrastructure/CurrentTeacherDepartureTest.php`
- Test (new): `tests/Classes/Integration/Infrastructure/{CurrentSectionLearnersTest,CurrentSubjectRecordsTest}.php`, `tests/Schools/Integration/Api/SchoolDirectoryTest.php`, repository tests for every new named method

**Interfaces:**
- Produces:
  - Repository interfaces with today's query methods plus `findById(int): ?X`, `add(X): void`, `remove(X): void` (each saved on the next flush), and a named method for every generic `find…`/`count` call legacy code makes on these repositories (found by PHPStan after the rewrite).
  - `SectionLearnersInterface`: `countEnrolled(Section): int`, `countPendingTransfersInto(Section): int`, `countEnrolledBySection(School, SchoolYear): array<int, int>` (section id ⇒ learners).
  - `SubjectRecordsInterface`: `countGrades(Subject): int`, `countGradeChangeRequests(Subject): int`.
  - `SchoolDirectoryInterface` (`const MANAGE = 'SCHOOL_MANAGE'`): `findSchool(int $id): ?School`, `currentYear(): ?SchoolYear`, `previousYear(SchoolYear): ?SchoolYear`, `schoolOf(User, SchoolYear, string $role): ?School`, `teachersOf(School, SchoolYear): list<User>` (by full name), `blockedReason(School): ?string` (`PlanLimits::blockedReason`).
  - `InUseException(string $message)` code `IN_USE`.

- [ ] **Step 1: Save the HEAD route dump** (scratchpad).
- [ ] **Step 2: Write the tests** listed above (hooks against real enrollments, transfer requests, grades, change requests; API against real schools, years, assignments).
- [ ] **Step 3: Move and rewrite** (FQCN map script), then interfaces, mapping, deptrac, hooks, API; replace generic repository calls in legacy code with named methods; fixtures flush after manager calls.
- [ ] **Step 4: Setup checks:** `cache:clear`, `lint:container`, `doctrine:schema:validate`, `doctrine:migrations:diff` empty.

### Task 2: Sections

**Files:**
- Move: `src/Service/SectionManager.php` → `src/Classes/Application/Service/`; `src/Form/SectionFormType.php` → `src/Classes/Presentation/Form/` (binds to `SectionDetails`; `adviser` becomes a `ChoiceType` of teacher ids labelled by full name, `property_path: 'adviserId'`, same placeholder; the SUBMIT listener clears track/strand on the command); `src/Validator/{UniqueSection,UniqueSectionValidator}.php` → `src/Classes/Presentation/Validation/` (validates `SectionDetails`); `assets/controllers/section_form_controller.js` → `src/Classes/Presentation/assets/controllers/`
- Create: `src/Classes/Application/UseCase/Command/{SectionDetails,CreateSectionCommand,UpdateSectionCommand,DeleteSectionCommand,BringBackSectionsCommand,CreateOwnSectionCommand}.php`, `src/Classes/Application/Query/Command/{ListSectionsCommand,GetSectionFormCommand,GetOwnSectionFormCommand}.php`, their handlers, `src/Classes/Application/Transformer/SectionTransformer.php`, `src/Classes/Presentation/Validation/{ValidSection,ValidSectionValidator}.php` (the old `validateLevelAndTrack` rules), `src/Classes/Presentation/Controller/{SchoolSectionController,TeacherSectionController}.php`
- Move templates: `templates/school_sections/{index,form,_form}.html.twig`, `templates/teacher/section.html.twig` → `src/Classes/Presentation/templates/` (same relative paths)
- Delete: `src/Controller/SchoolSectionController.php`, the `createSection` action and section imports of `src/Controller/TeacherController.php` (its dashboard keeps `SectionManager::refusal()`)
- Move tests: `tests/Controller/SchoolSectionControllerTest.php` → `tests/Classes/Functional/`; the two own-section cases of `tests/Controller/TeacherAreaTest.php` → `tests/Classes/Functional/TeacherSectionControllerTest.php`; the section cases of `tests/Service/InUseProtectionTest.php` and `tests/Service/SectionAdvisoryCleanupTest.php` → `tests/Classes/Integration/Application/`
- Test (new): `tests/Classes/Integration/Application/SectionHandlersTest.php`, `tests/Classes/Unit/Presentation/{UniqueSectionValidatorTest,ValidSectionValidatorTest}.php`, `tests/Classes/Javascript/section_form_controller.test.js`

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `SectionManager`: `refusal(School): ?string`; `create(Section): void` (log "Created section <label>", plus "Assigned adviser <username> to <label>" when it has one); `update(Section, GradeLevel, string $name, ?ShsTrack, ?string $strand, ?User $adviser): void` (refuses "has a timetable" before applying when grade/track/strand change and the section has loads; log "Updated section <label>", adviser line only when the adviser changed); `delete(Section)`; `bringBack(School, SchoolYear $from, SchoolYear $to): int`. Plan blocked → `PlanLimitException`.
  - `SectionDetails(int $schoolId)` with `gradeLevel` (`NotNull('Choose the grade level.')`), `name` (`NotBlank('Enter the section name.')`, `Length(max: 60)`), `track`, `strand` (`Length(max: 60)`), `adviserId`; form constraints `ValidSection` and `UniqueSection` (same messages and paths as today).
  - `ListSectionsCommand(int $schoolId)` → `['school', 'schoolYear' => ?SchoolYearData, 'groups' => array<string, list<SectionData>>, 'enrolled' => array<int, int>, 'refusal' => ?string, 'bringBackFrom' => ?SchoolYearData]`; `SectionData` has the keys the templates read (`id`, `name`, `label`, `track` (enum), `strand`, `adviser` = `?{fullName, initials}`, `schoolYear` = `{name}`).
  - Flashes: "Created <label>.", "Saved <label>.", "Deleted <label>.", "Brought back <n> section(s) from S.Y. <year>. Assign their advisers.", own section "Created <label>. You are its adviser.", "You already advise <label>." (error, back to the dashboard), 403 "Your principal creates the sections of your school.". Unknown section → 404 "This section is not in the school this school year."; no current year → 404 "No current school year."; no previous year → 404 "There is no previous school year.".
- [ ] **Step 1: Move the tests** and add cases for each validation message, an unknown section (404) and a refused update leaving the stored values; handler and validator tests; Vitest test (shows the SHS rows only for grade ≥ 11, on connect and on change).
- [ ] **Step 2: Implement** service, commands, queries, handlers, transformer, constraints, form, controllers, templates, JS move.
- [ ] **Step 3: Setup checks:** `lint:container`, `lint:twig`, route table identical to HEAD.

### Task 3: Subjects

**Files:**
- Move: `src/Service/SubjectManager.php` → `src/Classes/Application/Service/`; `src/Form/SubjectFormType.php` → `src/Classes/Presentation/Form/` (binds to `SubjectDetails`; `parent` becomes a `ChoiceType` of subject ids labelled with the subject label and the same `data-grade` choice attribute, `property_path: 'parentId'`); `src/Validator/{UniqueSubject,UniqueSubjectValidator}.php` → `src/Classes/Presentation/Validation/`; `src/Controller/SubjectScope.php` → `src/Classes/Application/Service/Subject/SubjectScope.php` (without layout and routes, which stay in the controllers); `assets/controllers/subject_form_controller.js` → `src/Classes/Presentation/assets/controllers/`
- Create: `src/Classes/Application/Service/Subject/SubjectScopeResolver.php`, `src/Classes/Application/UseCase/Command/{SubjectDetails,CreateSubjectCommand,UpdateSubjectCommand,DeleteSubjectCommand,AddDepEdSubjectsCommand,BringBackSubjectsCommand}.php`, `src/Classes/Application/Query/Command/{ListSubjectsCommand,GetSubjectFormCommand,GetSubjectScopeCommand}.php`, their handlers, `src/Classes/Application/Transformer/SubjectTransformer.php`, `src/Classes/Presentation/Validation/{ValidSubject,ValidSubjectValidator}.php` (the old `validateLevelAndParent` rules), `src/Classes/Presentation/Controller/{SchoolSubjectController,TeacherSubjectController}.php`
- Move templates: `templates/school_subjects/{index,form}.html.twig`, `templates/teacher/subjects_need_section.html.twig` → `src/Classes/Presentation/templates/`
- Delete: `src/Controller/{SchoolSubjectController,TeacherSubjectController,AbstractSubjectController,SubjectScope}.php`
- Move tests: `tests/Controller/{SchoolSubjectControllerTest,TeacherSubjectsTest}.php` → `tests/Classes/Functional/`; `tests/Service/SubjectManagerTest.php` and the subject cases of `InUseProtectionTest` → `tests/Classes/Integration/Application/`
- Test (new): `tests/Classes/Integration/Application/SubjectHandlersTest.php`, validator tests, `tests/Classes/Javascript/subject_form_controller.test.js`

**Interfaces:**
- Consumes: Tasks 1 and 2.
- Produces:
  - `SubjectScopeKey`: `SubjectScopeKey::school(int $schoolId)` or `SubjectScopeKey::teacher(int $teacherId)`; every subject command and query carries one. `SubjectScopeResolver::resolve(SubjectScopeKey): SubjectScope` — school: current year or 404 "No current school year.", grades = the school type's; teacher: 403 `SubjectScopeDeniedException` "Your principal sets up the subjects of your school." when the plan has a principal, `NoAdvisoryException` when there is no advisory in their school (index shows the need-section page, other actions redirect to `teacher_subjects`), grades = the advisory's grade, default track/strand from it.
  - `SubjectManager`: `refusal(School)`, `create(Subject)`, `update(Subject, SubjectDetails $details, ?Subject $parent)` (refuses before applying, today's rules and messages; components inherit SHS settings; a subject with components gets no meetings), `delete(Subject)`, `addDepEdSet(School, SchoolYear, GradeLevel): int`, `bringBack(School, SchoolYear $from, SchoolYear $to, ?array $grades): int`; same log lines.
  - `SubjectDetails` with `gradeLevel` (`NotNull('Choose the grade level.')`), `code` (`NotBlank('Enter the subject code.')`, `Length(max: 20)`, `Regex('/^[A-Z0-9-]*$/', 'Use only letters, numbers and dashes in the code.')`), `name` (`NotBlank('Enter the subject name.')`, `Length(max: 100)`), `parentId`, `position` (`NotNull`, `Positive`), `meetingsPerWeek` (`Range(1, 10)`), `semester` (`Choice([1, 2])`), `track`, `strand` (`Length(max: 60)`); form constraints `ValidSubject` and `UniqueSubject`.
  - `ListSubjectsCommand` → `['school', 'schoolYear', 'groups' => array<string, list<SubjectData>>, 'refusal', 'depedGrades' => list<GradeLevel>, 'bringBackFrom' => ?SchoolYearData]`; `SubjectData` keys: `id`, `code`, `name`, `label`, `component`, `components` (list of `SubjectData`), `hasComponents`, `track` (enum), `strand`.
  - Flashes and 404s as today ("Created/Saved/Deleted <label>.", "Added <n> DepEd subjects for <grade>.", "Brought back <n> subjects from S.Y. <year>.", "This subject is not offered here this school year.", "There is no DepEd subject list for this grade level here.").
- [ ] **Step 1: Move the tests** and add cases per validation message, the teacher 403-before-CSRF order, and a refused update leaving the stored values; handler, resolver, validator and Vitest tests.
- [ ] **Step 2: Implement.**
- [ ] **Step 3: Setup checks:** `lint:container`, `lint:twig`, route table identical to HEAD.

### Task 4: Class periods

**Files:**
- Move: `src/Service/ClassPeriodManager.php` → `src/Classes/Application/Service/`; `src/Form/ClassPeriodFormType.php` → `src/Classes/Presentation/Form/` (binds to `ClassPeriodDetails`, `isBreak` mapped to the command, same "Enter the break name." error); `src/Validator/{NoOverlappingPeriod,NoOverlappingPeriodValidator}.php` → `src/Classes/Presentation/Validation/`
- Create: `src/Classes/Domain/Service/Period/ClassPeriodDay.php` (in-memory numbering and labels), `src/Classes/Application/UseCase/Command/{ClassPeriodDetails,CreateClassPeriodCommand,UpdateClassPeriodCommand,DeleteClassPeriodCommand}.php`, `src/Classes/Application/Query/Command/{ListClassPeriodsCommand,GetClassPeriodFormCommand}.php`, their handlers, `src/Classes/Application/Transformer/ClassPeriodTransformer.php`, `src/Classes/Presentation/Controller/SchoolClassPeriodController.php`
- Move templates: `templates/school_class_periods/{index,form}.html.twig` → `src/Classes/Presentation/templates/`
- Delete: `src/Controller/SchoolClassPeriodController.php`
- Move tests: `tests/Controller/SchoolClassPeriodControllerTest.php` → `tests/Classes/Functional/`; the period cases of `InUseProtectionTest` → `tests/Classes/Integration/Application/` (the teacher-departure case moves to `tests/Schools/Integration/Application/`; `tests/Service/InUseProtectionTest.php` is then deleted)
- Test (new): `ClassPeriodHandlersTest`, `ClassPeriodDayTest`, validator test

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - Every write handler first takes `school-timetable-<schoolId>` with "Another change is being saved. Please try again.".
  - `ClassPeriodManager`: `refusal(School)`, `create(ClassPeriod): string` / `update(ClassPeriod, ClassPeriodDetails): string` / `delete(ClassPeriod): string` (each returns the label for the flash; refuses "has classes" before turning a period into a break or deleting it; re-sorts positions by start time; logs "Added/Updated/Deleted class period <label>").
  - `ClassPeriodDay` (static): `sorted(list<ClassPeriod>): list<ClassPeriod>` (start time, then id, new periods last among equals) and `label(ClassPeriod, list<ClassPeriod> $day): string` ("Period <n>" counting non-break periods of the sorted day up to it, or the break label).
  - `ClassPeriodDetails(int $schoolId)` with `startsAt` (`NotNull('Enter the start time.')`), `endsAt` (`NotNull('Enter the end time.')`), `isBreak`, `breakLabel` (`Length(max: 40)`); form constraint `NoOverlappingPeriod` plus the end-after-start rule ("The end time must be after the start time." under `endsAt`); the form re-renders with 422 on errors.
  - Flashes "Added <label>.", "Saved <label>.", "Deleted <label>."; 404 "This class period is not part of the school day this school year.".
- [ ] **Step 1: Move the tests** and add cases per validation message and the lock wait; handler and `ClassPeriodDay` tests.
- [ ] **Step 2: Implement.**
- [ ] **Step 3: Setup checks:** `lint:twig`, route table identical to HEAD.

### Task 5: Baselines, docs, comments, deprecations

- [ ] **Step 1:** phpcbf, then phpcs clean. PHPStan on the module folders with no errors, then baseline regeneration under the Global Constraints rules; deptrac 0 violations; ESLint and Stylelint clean.
- [ ] **Step 2: Docs.** `CLAUDE.md`: the Classes module (entities, repositories, sections/subjects/class periods, `teacher_section_new`), its hooks (`SectionLearnersInterface`, `SubjectRecordsInterface`), Schools' `SchoolDirectoryInterface`, `CurrentTeacherDeparture` now in Classes, timetable and teacher load still legacy until 3b, `src/Classes` in the coverage gate.
- [ ] **Step 3: Comment sweep and deprecation check** (`debug:container --deprecations`, ESLint) over everything touched.

### Task 6: Verification

- [ ] **Step 1: Scoped run** of `tests/Classes tests/Schools tests/Access tests/Core tests/Controller tests/Service tests/Security tests/DataFixtures` with `--display-phpunit-notices`, then `make test vitest`; fix, re-run only failures.
- [ ] **Step 2: Coverage** of the module tests → nothing uncovered (PHP and JS).
- [ ] **Step 3: Full runs on the final code, one at a time:** `make test all NO_PARALLEL=1`, `make test-feature`, `make regression-test`; schema valid, diff empty; route table identical to HEAD.
- [ ] **Step 4: Fresh whole-change review** (most capable model, read-only); fix Critical/Important with a failing test first; full runs again.
- [ ] **Step 5: Ask the owner before the live-browser check, and whether headless or visible.** If headless: HEAD vs branch on the same dev data (data reset between sides): section list/new/edit (validation errors, refused timetable change), bring back sections, an Individual-plan teacher's own section, subject list/new/edit/delete, DepEd subjects, teacher subjects, class period list/new/edit (overlap, break), delete; then restore the dev data.
- [ ] **Step 6: Report** and propose the commit; commit only after approval:

```
Move sections, subjects and class periods into the Classes module

Create the Classes module with the section, subject, class period,
teaching load and timetable entry entities and their repositories, and
move the section, subject and class period screens into it, with every
action through the bus. Learner and grade counts reach it through hooks,
schools and school years through a Schools API. Pages, messages and URLs
are unchanged.
```
