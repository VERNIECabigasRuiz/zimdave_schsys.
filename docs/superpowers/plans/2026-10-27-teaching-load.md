# Teaching Load and Class Timetables Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Principals plot each section's Mon–Fri timetable by class period, with a teacher per subject per section (the teaching load) and no teacher double-booked. Teachers see their load, and both dashboards show who is teaching right now.

**Architecture:** Four new entities: `ClassPeriod`, `TeachingLoad`, `ScheduleEntry`, plus the `Weekday` enum. Two new services do the work: `TimetableManager` for every timetable write, under a per-school `symfony/lock`, and `TimetableQuery` for reads (grid, busy map, teacher grid, "now"). Server-rendered pages follow the Sections/Subjects pattern. The section timetable is a Stimulus controller that saves each action through small JSON endpoints using `#[MapRequestPayload]` DTOs. The dashboard cards are Turbo frames that reload every minute.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3 (MariaDB 10.4), Twig, Stimulus + Turbo (AssetMapper), symfony/lock, symfony/clock, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-27-teaching-load-design.md`

## Global Constraints

- Project root `C:\xampp\htdocs\zimdave_schsys`; PHP is `C:\xampp\php\php.exe`; run tests with `php bin/phpunit` (one phpunit process at a time; tests share the `zimdave_schsys_test` DB). Apache and MySQL must be running (start them with working directory `C:\xampp`).
- Work on branch `feature/subjects`; commit after each task with the message given, ending with the `Co-Authored-By` line from the session.
- TDD: every step that adds behavior starts with a failing test, run and seen failing.
- Schema changes only via `php bin/console make:migration`, then `doctrine:migrations:migrate --no-interaction` for dev **and** `--env=test`.
- Access: edit pages use `#[IsGranted(SchoolVoter::MANAGE, 'school')]` under `/schools/{id}/...` (principal of the school and admin). A section, subject, period, teacher or entry id from another school or school year → 404.
- Pages work on the current school year only (`SchoolYearRepository::findCurrent()`), like Sections and Subjects.
- Every write is refused while `PlanLimits::blockedReason($school)` is not null (view-only plan), throwing `PlanLimitException`, the same as `SubjectManager`.
- Days: Monday to Friday only. `term`: 0 = full year (Kinder–10), 1 or 2 = semester (Grades 11–12, from the subject's `semester`).
- "Now" uses Philippine time: parameter `app.timezone: 'Asia/Manila'`, read through `ClockInterface`.
- Exact copy (the spec's examples; `<section>` is `Section::getLabel()`, e.g. `Grade 8 – Mabini`; `<teacher>` is `User::getFullName()`; `<slot>` is `Mon, Period 3`):
  - `<section> already has <subject name> on <slot>.`
  - `<teacher> already teaches <subject name> in <section> on <slot>.`
  - `<subject name> already has all <n> meetings this week.`
  - `Pick a teacher for <subject name> first.` · `Set meetings per week for <subject name> on the Subjects page first.`
  - `Remove <subject name> from the timetable before clearing its teacher.`
  - `Another change is being saved. Please try again.`
  - `Period <n> has classes in <k> sections. Remove them first.` (all `<k> sections` and `<k> loads` copy uses the singular when k = 1)
  - `<subject name> is loaded in <k> sections.` · `<section> has a timetable. Remove its classes and teachers first.` · `<teacher> still has <k> loads this school year.`
  - Activity log: `Set teacher <username> for <subject label> in <section>`, `Changed teacher to <username> for <subject label> in <section>`, `Cleared teacher for <subject label> in <section>`, `Plotted <subject name> (<teacher>) in <section>, <slot>`, `Removed <subject name> from <section>, <slot>`, `Added class period <label>`, `Updated class period <label>`, `Deleted class period <label>`.
- DepEd preset `meetingsPerWeek`: **5** for every top-level subject without components (DepEd Order 10, s. 2024 default class program: every learning area meets daily); **null** for MAPEH components (schools split MAPEH their own way; the principal sets them). A parent with components always stores null.
- Follow existing patterns: `SubjectManager` (plan block, activity log), `SchoolTeacherLock` (lock + `UniqueConstraintViolationException`), `SchoolSubjectController` / `AbstractSubjectController`, `region_division_controller.js`, `SchoolSubjectControllerTest` (`AdminTestCase` + `ClockSensitiveTrait`).

## Review Focus

1. A teacher with a **full-year class (term 0)** in one section and an SHS section plotted in **semester 1 at the same slot** must be refused, and the busy map must show red for it. The unique index only covers the same term. Covered by the Task 4 test `testFullYearClashesWithSemester` and the Task 6 test `testBusyMapCrossTerm`.
2. **Changing a class period's times** so it overlaps its neighbour must be refused, even though its entries stay attached. Covered by the Task 2 test `testOverlapRejectedOnEdit`.
3. **Two concurrent drops into the same cell** (two tabs) must leave one entry and give the other the "Another change is being saved" message, not a 500. Covered by the Task 4 test `testUniqueViolationBecomesFriendlyMessage`, which simulates it by inserting the clashing row directly.
4. A **teacher removed or moved to another school** while still holding loads must be refused, both from the admin user screen and from the service. Covered by the Task 5 test `testTeacherWithLoadsCannotBeRemovedOrMoved`.
5. **The "now" card at the exact boundary** (10:45 when Period 3 ends at 10:45 and Period 4 starts at 10:45) must show Period 4, and 16:00 after a last period ending at 16:00 must show "after classes". Covered by the Task 10 test `testBoundaryBelongsToNextPeriod`.

---

### Task 1: Meetings per week on subjects

**Files:**
- Modify: `src/Entity/Subject.php`, `src/Form/SubjectFormType.php`, `src/Service/SubjectManager.php` (`addDepEdSet`, `save`), subject form template under `templates/school_subjects/`, `assets/controllers/subject_form_controller.js` (hide the field when the subject has components)
- Create: migration
- Test: `tests/Entity/SubjectTest.php`, `tests/Service/SubjectManagerTest.php`, `tests/Controller/SchoolSubjectControllerTest.php`

**Interfaces:**
- Produces: `Subject::getMeetingsPerWeek(): ?int`, `setMeetingsPerWeek(?int): static`; `Subject::isLoadable(): bool` (= `!hasComponents()`).

- [ ] **Step 1: Write failing tests**
  - `SubjectTest::testMeetingsPerWeekRange`: 0 and 11 give a violation on `meetingsPerWeek`; 1 and 10 are fine. A subject without components and with null gives the violation "Set how many times a week this subject meets."
  - `SubjectManagerTest::testDepEdSetFillsMeetingsPerWeek`: Grade 7 preset gives `MATH7` 5 and `MAPEH7` null, and `MA7` and `PEH7` are null.
  - `SubjectManagerTest::testParentWithComponentsStoresNull`: a parent saved with 4 that has components → 4 is replaced with null after `save()`.
  - `SchoolSubjectControllerTest::testFormSavesMeetingsPerWeek`: post the new-subject form with `meetingsPerWeek` 4 → stored 4. Update the existing form-posting tests in this file and in `TeacherSubjectsTest` to send a value.
- [ ] **Step 2: Run** `php bin/phpunit tests/Entity/SubjectTest.php tests/Service/SubjectManagerTest.php tests/Controller/SchoolSubjectControllerTest.php`. Expected: the new tests fail (unknown method or field).
- [ ] **Step 3: Implement.** Add the column `meetings_per_week SMALLINT NULL`, with `#[Assert\Range(min: 1, max: 10)]`. Add the NotNull rule for subjects without components inside the existing `validateLevelAndParent` callback. Add an `IntegerType` field labelled "Meetings per week", `required: false`. `SubjectManager::save()` nulls it on a subject with components. `addDepEdSet` sets 5 on top-level subjects without components. Run `make:migration` and migrate dev and test.
- [ ] **Step 4: Run** the full suite `php bin/phpunit`. Expected: all pass.
- [ ] **Step 5: Commit** `feat: meetings per week on subjects`.

### Task 2: Class periods (entity, rules, pages)

**Files:**
- Create: `src/Entity/ClassPeriod.php`, `src/Repository/ClassPeriodRepository.php`, `src/Entity/Weekday.php`, `src/Service/ClassPeriodManager.php`, `src/Form/ClassPeriodFormType.php`, `src/Controller/SchoolClassPeriodController.php`, `templates/school_class_periods/index.html.twig`, `templates/school_class_periods/form.html.twig`, migration
- Modify: `templates/principal/_layout.html.twig` (nav "Class Periods" after Subjects), `tests/SchoolFixturesTrait.php` (`clearSchoolData()` deletes `ClassPeriod` before `Subject`; add helper `classPeriod(School, SchoolYear, string $start, string $end, ?string $breakLabel = null): ClassPeriod` that sets `position` to next)
- Test: `tests/Entity/ClassPeriodTest.php`, `tests/Controller/SchoolClassPeriodControllerTest.php`

**Interfaces:**
- Produces:
  - `enum Weekday: int { Monday = 1 … Friday = 5 }` with `short(): string` (`Mon`…`Fri`) and `static fromDate(\DateTimeImmutable): ?self` (null on Saturday and Sunday).
  - `new ClassPeriod(School, SchoolYear)`; `getId()`, `getSchool()`, `getSchoolYear()`, `get/setPosition(int)`, `get/setStartsAt(?\DateTimeImmutable)`, `get/setEndsAt(?\DateTimeImmutable)`, `get/setBreakLabel(?string)` (blank → null), `isBreak(): bool`, `getTimeRange(): string` (`07:30–08:30`).
  - `ClassPeriodRepository::findForSchool(School, SchoolYear): list<ClassPeriod>` (ordered by `startsAt`). `numbering(list<ClassPeriod>): array<int, int>` (period id → its number among class periods). `label(ClassPeriod): string` (`Period 3` or the break label).
  - `ClassPeriodManager::save(ClassPeriod): void` (re-sorts `position` by `startsAt` for the school year), `delete(ClassPeriod): void`. Both are plan-blocked and logged. The in-use refusal is added in Task 6.
  - Routes: `school_class_periods`, `school_class_periods_new`, `school_class_periods_edit` (`/{period}/edit`), `school_class_periods_delete` (`/{period}/delete`, POST, CSRF id `"class-period-" ~ period`).

- [ ] **Step 1: Write failing tests**
  - `ClassPeriodTest`: `testEndMustBeAfterStart`; `testOverlapRejected` (07:30–08:30 exists; 08:00–09:00 → violation "This time overlaps Period 1 (07:30–08:30)."); `testTouchingIsFine` (08:30–09:30 ok); `testOverlapRejectedOnEdit` (move an existing period's start into the previous one → violation); `testNumberingSkipsBreaks` (P1, Recess, P2 → numbers 1, 2, and the label of the break row is "Recess").
  - `SchoolClassPeriodControllerTest`: `testPrincipalAddsPeriodAndBreak` (both listed in time order, activity log `Added class period Period 1`); `testOtherSchoolPrincipalDenied` (403); `testPeriodOfOtherSchoolIs404`; `testBlockedPlanIsViewOnly` (expired plan → flash error, nothing saved).
- [ ] **Step 2: Run** `php bin/phpunit tests/Entity/ClassPeriodTest.php tests/Controller/SchoolClassPeriodControllerTest.php`. Expected: fails, class not found.
- [ ] **Step 3: Implement.** Columns: `starts_at TIME`, `ends_at TIME`, `break_label VARCHAR(40) NULL`, `position INT`. The overlap check is a class-level validator constraint `NoOverlappingPeriod`, which queries the repository and ignores itself. The form has `startsAt` and `endsAt` (`TimeType`, `widget: single_text`, step 300), an `isBreak` checkbox (unmapped), and `breakLabel`. The controller follows `SchoolSubjectController`. Run the migration.
- [ ] **Step 4: Run** the same tests. Expected: pass. Then run the full suite.
- [ ] **Step 5: Commit** `feat: class periods per school year`.

### Task 3: TeachingLoad and ScheduleEntry entities

**Files:**
- Create: `src/Entity/TeachingLoad.php`, `src/Entity/ScheduleEntry.php`, `src/Repository/TeachingLoadRepository.php`, `src/Repository/ScheduleEntryRepository.php`, migration
- Modify: `tests/SchoolFixturesTrait.php` (`clearSchoolData()` deletes `ScheduleEntry`, then `TeachingLoad`, before `ClassPeriod`)
- Test: `tests/Entity/TimetableEntitiesTest.php`

**Interfaces:**
- Produces:
  - `new TeachingLoad(Section $section, Subject $subject, User $teacher)`; `getId()`, `getSection()`, `getSubject()`, `get/setTeacher(User)`, `getEntries(): Collection<ScheduleEntry>`, `getCreatedAt()`. Unique `uniq_load_section_subject` (`section_id`, `subject_id`).
  - `new ScheduleEntry(TeachingLoad $load, Weekday $day, ClassPeriod $period)`. The constructor copies `section`, `teacher`, `schoolYear` from the load and sets `term` = `subject->getSemester() ?? 0`. Getters for all of these, plus `syncTeacher(): void` (re-copies the teacher from the load). Unique `uniq_entry_section_slot` (`section_id`, `day`, `period_id`, `term`) and `uniq_entry_teacher_slot` (`teacher_id`, `school_year_id`, `day`, `period_id`, `term`). `load` and `period` use `onDelete: RESTRICT`.
  - `TeachingLoadRepository::findForSection(Section): list<TeachingLoad>`, `findOne(Section, Subject): ?TeachingLoad`, `countForSubject(Subject): int` (the subject or its components), `countForTeacher(User, SchoolYear): int`, `findForTeacher(User, SchoolYear): list<TeachingLoad>`.
  - `ScheduleEntryRepository::findForSection(Section, int $term): list<ScheduleEntry>`, `findForTeacher(User, SchoolYear): list<ScheduleEntry>`, `findTeacherClash(User, SchoolYear, Weekday, ClassPeriod, int $term, Section $exceptSection): ?ScheduleEntry` (overlapping terms: 0 matches all, 1 matches {0,1}, 2 matches {0,2}), `findSectionClash(Section, Weekday, ClassPeriod, int $term): ?ScheduleEntry`, `countForPeriod(ClassPeriod): int` (distinct sections), `findAt(School, SchoolYear, Weekday, ClassPeriod): list<ScheduleEntry>`.

- [ ] **Step 1: Write failing test** `TimetableEntitiesTest`: `testEntryCopiesLoadAndTerm` (Grade 7 → term 0; Grade 11 subject with semester 2 → term 2); `testSectionSlotUnique` (second entry in the same section, day, period and term → `UniqueConstraintViolationException` on flush); `testTeacherClashFindsCrossTerm` (term-0 entry; `findTeacherClash(..., term 1, other section)` returns it; term-1 entry vs term 2 → null).
- [ ] **Step 2: Run** `php bin/phpunit tests/Entity/TimetableEntitiesTest.php`. Expected: fails.
- [ ] **Step 3: Implement** the entities, repositories and migration. Migrate dev and test.
- [ ] **Step 4: Run** the test, then the full suite. Expected: pass.
- [ ] **Step 5: Commit** `feat: teaching load and schedule entry entities`.

### Task 4: TimetableManager

**Files:**
- Create: `src/Service/TimetableManager.php`, `src/Service/TimetableException.php` (extends `\RuntimeException`; `getClashes(): list<string>`)
- Test: `tests/Service/TimetableManagerTest.php`

**Interfaces:**
- Consumes: the Task 3 repositories, `PlanLimits`, `ActivityLogger`, `LockFactory`, `SchoolAssignmentRepository::findSchoolOf(User, SchoolYear, ROLE_TEACHER)`, `SubjectRepository::findForSection`, `ClassPeriodRepository::numbering/label`.
- Produces:
  - `setTeacher(Section $section, Subject $subject, ?User $teacher): ?TeachingLoad`
  - `plot(Section $section, Subject $subject, Weekday $day, ClassPeriod $period): ScheduleEntry`
  - `remove(ScheduleEntry $entry): void`
  - All three throw `TimetableException` (the exact copy from Global Constraints) or `PlanLimitException`. Lock key `school-timetable-{schoolId}`. A lock that isn't acquired, or a `UniqueConstraintViolationException`, gives `TimetableException('Another change is being saved. Please try again.')`, and the entity manager is reset the way `SchoolTeacherLock` handles it.

- [ ] **Step 1: Write failing tests** (`KernelTestCase` + `SchoolFixturesTrait` + `ClockSensitiveTrait`; a school with P1 07:30–08:30, P2 08:30–09:30, Recess, P3; sections `Grade 7 – Rizal` and `Grade 8 – Mabini`; teacher `santos` with full name "Maria Santos"):
  - `testSetTeacherCreatesChangesAndClears` (log lines exact); `testTeacherMustBelongToSchool`; `testSubjectWithComponentsRefused`; `testSubjectOfOtherGradeRefused`.
  - `testPlotRefusesWithoutTeacher` / `testPlotRefusesWithoutMeetingsPerWeek` / `testMeetingsPerWeekLimit` ("Mathematics already has all 5 meetings this week.").
  - `testSectionSlotTaken` ("Grade 7 – Rizal already has Science on Mon, Period 1.").
  - `testTeacherBusyElsewhere` ("Maria Santos already teaches Mathematics in Grade 8 – Mabini on Mon, Period 1."); `testBackToBackIsFine` (P1 in one section, P2 in the other).
  - `testFullYearClashesWithSemester` (Integrated school; Grade 10 term-0 entry, then a Grade 11 sem-1 subject at the same slot for the same teacher → refused).
  - `testBreakRefused`; `testPeriodOfOtherSchoolRefused`.
  - `testChangeTeacherWithClashesListsAll` (new teacher busy at 2 of the 5 slots → nothing changes; `getClashes()` has 2 lines); `testChangeTeacherMovesEntries` (the entries' `teacher` is updated).
  - `testClearTeacherWithEntriesRefused` ("Remove Mathematics from the timetable before clearing its teacher.").
  - `testUniqueViolationBecomesFriendlyMessage`: save a real Science entry at Mon P1 in Grade 7 – Rizal. Build a `TimetableManager` by hand whose `ScheduleEntryRepository` is a stub that returns null from `findSectionClash` and `findTeacherClash` (as if the other save happened after the check). `plot` Mathematics at Mon P1 → `TimetableException` "Another change is being saved. Please try again.", and only the Science entry exists.
  - `testBlockedPlanRefuses` (`PlanLimitException`).
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/TimetableManagerTest.php`. Expected: fails.
- [ ] **Step 3: Implement.** Check order in `plot`: plan → subject in `findForSection` and loadable → period is a class period of the section's school and year → load exists → `meetingsPerWeek` set → used < per week → section clash → teacher clash → persist, log, flush. All of it runs inside the lock.
- [ ] **Step 4: Run** the test, then the full suite. Expected: pass.
- [ ] **Step 5: Commit** `feat: timetable manager with conflict checks`.

### Task 5: In-use protection

**Files:**
- Create: `src/Service/InUseException.php` (extends `\RuntimeException`)
- Modify: `src/Service/ClassPeriodManager.php` (refuse delete, or turning a period into a break, while `countForPeriod > 0`), `src/Service/SubjectManager.php::delete`, `src/Service/SectionManager.php::delete`, `src/Service/SchoolTeacherManager.php::removeFromSchool` and `assignToSchool` (the move branch; throw `AssignmentException`), `src/Controller/AbstractSubjectController.php::handleDelete`, `src/Controller/SchoolSectionController.php::delete`, `src/Controller/SchoolClassPeriodController.php` (catch → flash error), `src/Controller/Admin/UserAccountController.php:219` (catch `AssignmentException` → flash error)
- Test: `tests/Service/InUseProtectionTest.php`

- [ ] **Step 1: Write failing tests**: `testPeriodInUse` ("Period 1 has classes in 1 section. Remove them first."; delete and turn-into-break both refused, a time change is allowed); `testSubjectInUse` (MAPEH whose component MA7 is loaded → "MAPEH is loaded in 1 section."); `testSectionInUse`; `testTeacherWithLoadsCannotBeRemovedOrMoved` ("Maria Santos still has 2 loads this school year.", for both `removeFromSchool` and a move through `assignToSchool`). Also add one HTTP test in `SchoolSubjectControllerTest::testDeleteLoadedSubjectShowsError` (flash shown, subject still there).
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/InUseProtectionTest.php tests/Controller/SchoolSubjectControllerTest.php`. Expected: fails.
- [ ] **Step 3: Implement** the checks with the Task 3 counts and the exact copy.
- [ ] **Step 4: Run** the full suite. Expected: pass.
- [ ] **Step 5: Commit** `feat: protect loaded subjects, sections, periods and teachers`.

### Task 6: TimetableQuery (grids and busy map)

**Files:**
- Create: `src/Service/TimetableQuery.php`
- Test: `tests/Service/TimetableQueryTest.php`

**Interfaces:**
- Produces:
  - `termsFor(Section): list<int>`: `[0]` for Kinder–10, `[1, 2]` for 11–12.
  - `sectionState(Section $section, int $term): array`, the JSON shape the page uses:
    `{term, terms, days: [{value, label}], periods: [{id, label, number|null, isBreak, startsAt 'H:i', endsAt 'H:i'}], teachers: [{id, name}], cards: [{subjectId, name, teacherId|null, teacherName|null, used, perWeek|null}], cells: [{entryId, day, periodId, subjectId, name, teacherName}], busy: {"<teacherId>": {"<day>-<periodId>": "<subject name>, <section label>"}}}`.
    Cards are `findForSection` subjects that are loadable and, for SHS, have `semester == term`. Teachers come from `SchoolAssignmentRepository::findTeacherAssignments`. `busy` lists other sections' entries with overlapping terms.
  - `teacherWeek(User $teacher, SchoolYear $schoolYear, int $term): array` → `{periods, days, cells: [{day, periodId, name, sectionLabel}], loads: [{subjectName, sectionLabel, perWeek, semester|null}], totalPerWeek}`. `totalPerWeek` is the count of entries in the term, with term 0 counted in both semesters.

- [ ] **Step 1: Write failing tests**: `testSectionStateShape` (keys and the values of one card and one cell); `testCardsHideParentsAndOtherSemester`; `testBusyMapListsOtherSectionsOnly`; `testBusyMapCrossTerm`; `testTeacherWeekTotals`.
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/TimetableQueryTest.php`. Expected: fails.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the test. Expected: pass.
- [ ] **Step 5: Commit** `feat: timetable read model`.

### Task 7: Section timetable page and JSON endpoints

**Files:**
- Create: `src/Controller/SchoolTimetableController.php`, `src/Dto/TimetableTeacherInput.php` (`?int $teacherId`), `src/Dto/TimetablePlotInput.php` (`#[Assert\NotNull] int $subjectId`, `#[Assert\Range(min: 1, max: 5)] int $day`, `#[Assert\NotNull] int $periodId`), `templates/school_timetable/show.html.twig`
- Modify: `templates/school_sections/index.html.twig` (a "Timetable" button on each row)
- Test: `tests/Controller/SchoolTimetableControllerTest.php`

**Interfaces:**
- Consumes: `TimetableManager` (Task 4), `TimetableQuery::sectionState/termsFor` (Task 6).
- Produces routes under `/schools/{id}/sections/{section}/timetable`: `school_timetable` GET (page; `?term=`), `school_timetable_state` GET `/state?term=`, `school_timetable_load` PUT `/loads/{subject}`, `school_timetable_plot` POST `/entries`, `school_timetable_remove` DELETE `/entries/{entry}`. The writes have `#[IsCsrfTokenValid(new Expression('"timetable-" ~ args["section"]'), tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]`. They return `200 {state}` on success and `422 {message, clashes?}` on `TimetableException` or `PlanLimitException`. The page renders `data-timetable-*-value` attributes for the state URL, the write URLs and the CSRF token, and shows "Set up your class periods first" (linked to `school_class_periods`) when there are no class periods.

- [ ] **Step 1: Write failing tests**: `testPageRendersGridForPrincipal`; `testNoPeriodsShowsSetupLink`; `testAdminAllowed`; `testOtherSchoolPrincipalDenied`; `testTeacherDenied`; `testSectionOfOtherSchoolIs404`; `testPlotSavesAndReturnsState`; `testPlotClashReturns422WithMessage` (exact copy); `testWriteWithoutCsrfRejected`; `testSetTeacherAndClear`; `testRemoveEntry`; `testShsTermSwitch` (`?term=2` shows only sem-2 cards).
- [ ] **Step 2: Run** `php bin/phpunit tests/Controller/SchoolTimetableControllerTest.php`. Expected: fails.
- [ ] **Step 3: Implement** the controller (thin: resolve the section in the school and current year, else 404; map exceptions to 422), the DTOs and the server-rendered grid. Make the template usable without JS (it shows the grid).
- [ ] **Step 4: Run** the test, then the full suite. Expected: pass.
- [ ] **Step 5: Commit** `feat: section timetable page and endpoints`.

### Task 8: Interactive timetable (Stimulus)

**Files:**
- Create: `assets/controllers/timetable_controller.js`, styles in `assets/styles/` (follow the existing file layout)
- Modify: `templates/school_timetable/show.html.twig`

**Interfaces:**
- Consumes: the Task 7 data values and endpoints, and the Task 6 state shape.
- Behavior (from the spec and the mockup at `C:\Users\ZiM\AppData\Local\Temp\claude\C--xampp-htdocs-zimdave-schsys\4003718b-c739-4095-a98a-a2946a5b5a4c\scratchpad\timetable-mockup.html`): render from state. Card teacher `<select>` → PUT. Drag a card (`draggable` only with a teacher, a per-week value and `used < perWeek`) → free cells `.is-free`, busy cells `.is-busy` with the busy text. Drop → POST. Click an empty cell → `<dialog>` listing cards, with busy or full ones disabled and their reason → POST. ✕ → DELETE. On 422: show the message (and clashes) in the red message box, then reload the state. On 200: redraw from the returned state.

- [ ] **Step 1: Write the controller and template changes.**
- [ ] **Step 2: Verify in a real browser.** Log in as a principal, open a section timetable, set a teacher, drag to plot, drop on a busy cell (refused with the message), click-plot, remove, and switch the SHS term. Use the `run` skill or the claude-in-chrome skill. If no browser automation is available, ask the user to do these checks and report.
- [ ] **Step 3: Run** `php bin/console lint:twig templates/` and the full suite. Expected: clean.
- [ ] **Step 4: Commit** `feat: drag and drop timetable`.

### Task 9: Teacher's load pages

**Files:**
- Create: `src/Controller/TeacherLoadController.php` (two actions), `templates/teacher_load/show.html.twig` (shared; extends the layout passed in)
- Modify: `templates/school_teachers/index.html.twig` (a "Load" button), `templates/teacher/_layout.html.twig` (nav "My Teaching Load")
- Test: `tests/Controller/TeacherLoadControllerTest.php`

**Interfaces:**
- Consumes: `TimetableQuery::teacherWeek` (Task 6).
- Produces: `school_teacher_load` GET `/schools/{id}/teachers/{teacher}/load?term=` (`SchoolVoter::MANAGE`; the teacher must be assigned to the school this year, else 404) and `teacher_load` GET `/teacher/load?term=` (`ROLE_TEACHER`, own load only).

- [ ] **Step 1: Write failing tests**: `testPrincipalSeesTeacherLoadAndTotal` ("Total: 10 periods per week"); `testTeacherOfOtherSchoolIs404`; `testTeacherSeesOwnLoad`; `testTeacherHasNoEditControls` (no `data-controller="timetable"`).
- [ ] **Step 2: Run** `php bin/phpunit tests/Controller/TeacherLoadControllerTest.php`. Expected: fails.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the test. Expected: pass.
- [ ] **Step 5: Commit** `feat: teacher's load pages`.

### Task 10: "Now" and dashboard cards

**Files:**
- Create: `src/Service/SchoolClock.php` (`now(): \DateTimeImmutable` in `#[Autowire('%app.timezone%')]`, from `ClockInterface`), `assets/controllers/refresh_controller.js` (reloads its `turbo-frame` every `interval` ms, default 60000), `templates/principal/_now.html.twig`, `templates/teacher/_now.html.twig`
- Modify: `config/services.yaml` (parameter `app.timezone: 'Asia/Manila'`), `src/Service/TimetableQuery.php` (add `now` and `nowForTeacher`), `src/Controller/Principal/DashboardController.php` (route `principal_now` GET `/principal/now`), `src/Controller/TeacherController.php` (route `teacher_now` GET `/teacher/now`), both dashboard templates (`<turbo-frame id="now" src="…" data-controller="refresh">`)
- Test: `tests/Service/TimetableNowTest.php`, `tests/Controller/DashboardNowTest.php`

**Interfaces:**
- Produces:
  - `TimetableQuery::now(School, SchoolYear, \DateTimeImmutable $at): array` → `{state: 'class'|'break'|'before'|'after'|'weekend'|'no_periods', period: {label, timeRange}|null, classes: [{teacherName, subjectName, sectionLabel}], free: [teacherName], next: {label, startsAt}|null}`. `free` = school teachers with no entry in this period (state `class` only). The term is chosen from the date: semester 1 if `$at` falls before the school year's midpoint, otherwise 2. Term-0 entries always count.
  - `TimetableQuery::nowForTeacher(User, SchoolYear, \DateTimeImmutable $at): array` → `{state, current: {subjectName, sectionLabel, endsAt}|null, next: {subjectName, sectionLabel, startsAt}|null}`. `state` adds `'free'` (a class period with no class for this teacher) and `'done'` (no more classes today).
  - A period contains `$at` when `startsAt <= time < endsAt`.

- [ ] **Step 1: Write failing tests** with `static::mockTime(...)` in Asia/Manila:
  - `TimetableNowTest`: `testDuringPeriodListsClassesAndFreeTeachers`; `testDuringBreak`; `testBeforeFirstAndAfterLast`; `testWeekend`; `testBoundaryBelongsToNextPeriod`; `testTeacherNowAndNext`; `testTeacherDoneForTheDay`; `testTeacherFreePeriodShowsNextClass`.
  - `DashboardNowTest`: `testPrincipalDashboardHasNowFrame`; `testPrincipalNowFragment` (contains "Classes right now", "Free this period" and a teacher name); `testTeacherNowFragment` ("Now: Mathematics · Grade 7 – Rizal · until 08:30").
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/TimetableNowTest.php tests/Controller/DashboardNowTest.php`. Expected: fails.
- [ ] **Step 3: Implement**, using the card copy from the spec's "Dashboard cards" section.
- [ ] **Step 4: Run** the tests, then the full suite. Expected: pass.
- [ ] **Step 5: Commit** `feat: who is teaching now on dashboards`.

### Task 11: Final check

- [ ] **Step 1: Run** `php bin/phpunit`, `php bin/console lint:container`, `php bin/console lint:twig templates/`, and `php bin/console doctrine:schema:validate`. Expected: all clean.
- [ ] **Step 2:** In the browser, open the principal dashboard during a plotted period and confirm the card, then confirm the teacher's dashboard card.
- [ ] **Step 3: Commit** any fixes: `chore: teaching load final checks`.
