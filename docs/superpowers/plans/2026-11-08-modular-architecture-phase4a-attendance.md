# Modular architecture, Phase 4a (Attendance) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create the `Attendance` module with roll calls, learner marks and SF2 submissions, the SF2 report and spreadsheet, the adviser's roll call and month pages and the school's attendance pages, every action through the Core bus — with nothing a user would notice changing.

**Architecture:** Same patterns as 3c/3d. Entities lose `repositoryClass`; `AttendanceRecorder` becomes an Application service that checks and applies a change without locking or flushing; its use case handlers take the `attendance-section-<id>` lock (today's key) before reading anything and read the roll call and submission fresh (`findFresh()`), because each page's query runs first in the same request. `AttendanceVoter` becomes an Application `AttendancePolicy`. The SF2 builder moves to Application and the PhpSpreadsheet writer to Infrastructure behind a Domain interface. The SF2 roster (who counts on a section in a month) is Learners data used by SF2, SF8 and card slips, so it moves to the Learners public API. Card slips (Grading, still legacy) get their term totals from a new Attendance public API.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Twig, PhpSpreadsheet, PHPUnit 13, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row `Attendance`, sections 5, 6, 8, 11). Owner decisions (2026-11-08): Attendance is next; this ticket only migrates (regressions the move causes are fixed, existing flaws are left); the plan is committed with the phase.

**Branch:** `feature/modular-architecture` (3d committed as `7049068`, notes as `8851d63`, docs as `d778cf6`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 0. Planning | 2026-11-08 14:19:29 | 14:22:45 | 3m 16s |
| 1. Shared pieces: roster, calendar, section label | 14:54:58 | 14:58:20 | 3m 22s |
| 2. Attendance domain, repositories, SF2 report and writer | 14:58:20 | 15:05:55 | 7m 35s |
| 3. Attendance use cases, queries, policy and API | 15:05:55 | 15:14:30 | 8m 35s |
| 4. Attendance screens | 15:14:30 | 15:16:54 | 2m 24s |
| 5. Baselines, docs, comments, deprecations | 15:16:54 | 15:33:16 | 16m 22s |
| 6. Verification | 15:33:16 | 16:48:44 | 1h 15m 28s |

## Global Constraints

- Structure only: URLs, route names, HTTP methods, route requirements, CSRF token ids (`reopen-<section id>`, the roll call and SF2 submit forms), form field names (`roll_call_form[mark_<enrollment id>]`, `sf2_submit_form[...]`), flash and refusal messages, activity-log lines, status codes, redirects, access rules, the SF2 file name, content type and `Content-Disposition` fallback stay as today. Table and column names stay; `doctrine:migrations:diff` stays empty; the route table matches HEAD.
- Attendance may use Core, Access (Domain, Api), Schools (Domain, Api), Classes (Domain, Api) and Learners (Domain, Api). Legacy may use Attendance. Peers on the same level (Grading, Nutrition) reach Attendance only through `Attendance/Application/Api`.
- Controllers extend `BusController`; handlers refuse by throwing a `DomainException`; handlers that lock take the lock before any read and read what a page query may have loaded through `findFresh()`. Every class keeps its class-level attributes when moved or split (`#[IsGranted(User::ROLE_TEACHER)]` on the adviser's pages, `#[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]` on the school's).
- "Today" stays `SchoolClockInterface::now()` at midnight, as `AttendanceRules::today()` does now; stored timestamps keep today's time zone.
- New code: `declare(strict_types=1)`, PHPStan level 9 with no baseline entries, phpcs PSR-12 (lines ≤ 120), 100% coverage of `src/Attendance` (added to `<source>` in `phpunit.dist.xml`).
- Moved tests keep their assertions. New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first) with every setup line under `// Initialize`, and `// Execute` running the code under test; the existing helpers; `createStub` unless the test sets expectations. In functional tests, reload an entity through its repository after a request before changing it.
- Helper scripts: check the scratchpad first (`move3d.py`, `set_namespace.py`, `replace_text.py`, `wrap_long.py`, `prune_baseline.py`, `baseline_check.py`, `compare_routes.py`, `to_lf.py`, `worktree_package.sh`, the live-check scripts); new ones with the Write tool, writing LF; no inline heredocs; no `sed` for text with backslashes; no `git add -N`. Save the HEAD route dump and a baseline copy before the first code change.
- Tests are written per task and run once in Task 6, one command at a time, in Docker (`MSYS_NO_PATHCONV=1`). Read each check's output before recording it as passed. Clear `reports/phpstan/.cache` before a PHPStan run whose result counts. Long runs go to the background and are watched directly; leftover servers, monitors and shells are stopped.
- PHPStan baseline: prune entries for deleted files, regenerate on a clear cache; the total must not grow; no entry may name a module folder or a file not on `develop`.
- Live-browser check: ask first and confirm headless or visible; serve both sides through the `php -S` router; check the saved HTML isn't empty; reset the dev data before each side and restore it after. Comments short and plain; no Claude/Anthropic mention. One commit at the end after approval, through the Base hook, including this plan (`git add -f`).

## Review Focus

1. **Two saves at once.** Taking a roll call, submitting a month and reopening it wait for `attendance-section-<id>` ("Another change is being saved. Please try again."); a roll call or submission changed by another request between the page and the save is read fresh under the lock (a submitted month refuses a roll call with "<Month Year> is submitted and locked.", a second submit "<Month Year> is already submitted.", a reopen of an unsubmitted month "<Month Year> is not submitted."). Pinned by the moved `AttendanceRecorderTest` and new handler tests that run the page query first without clearing the entity manager.
2. **Who may do what.** The adviser takes attendance and submits only for their section this school year (not an admin who advises); the adviser, principal and admin view; the principal and admin reopen; a teacher who advises no section gets 403 with "You do not advise a section this school year."; a former teacher gets 403. Pinned by the moved controller tests and `AttendancePolicyTest`.
3. **Which days count.** Weekends, days off ("No classes: <reason>."), dates outside the school year, future dates and locked months are refused; learners count only from their roster dates (late enrollees, transfers in and out, dropouts); a school day without a roll call counts as present; the month can be submitted only after its last school day, confirming missing days. Pinned by the moved `Sf2ReportBuilderTest`, `SectionRosterTest`, `AttendanceRecorderTest`.
4. **SF2 output.** Same cells, summary figures, five-consecutive-absence follow-ups, late status after the 5th of the next month, file name and content type. Pinned by the moved `Sf2WriterTest`, `Sf2ReportBuilderTest`, `AttendanceStatisticsTest`.
5. **Legacy users keep working.** Card slips' term attendance (same counts), SF8 (same roster), grading and nutrition log lines ("Grade 8 – ARTISTIC"), the principal's and teacher's dashboard attendance cards, the school calendar's "sections have attendance on that day" check, the demo fixtures. Pinned by the legacy suite and the moved hook test.

## Rulings in this plan

- `Sf2Roster` and `RosterEntry` move to the Learners public API (`Learners/Application/Api/SectionRosterInterface::forMonth()`, `RosterEntry`): they describe who belongs to a section on which days, read only Learners data, and are used by SF2, SF8 and card slips. A `Learners_Api` deptrac layer splits from `Learners_Application`, as `Classes_Api` did.
- The four calendar reads attendance needs (`schoolDays`, `monthDays`, `dayOff`, `isSchoolDay`) join `SchoolDirectoryInterface`, delegating to `SchoolCalendar`.
- `AttendanceRecorder::label()` ("Grade 8 – ARTISTIC", used for log lines by grading and nutrition) becomes `Section::getFormLabel()` in Classes, so peers don't reach into Attendance for it.
- Card slips' counting moves into the Attendance public API: `AttendanceDirectoryInterface::tallies(Section, \DateTimeImmutable $from, \DateTimeImmutable $to): array<int, AttendanceTally>` (keyed by enrollment id, today's rules), with `AttendanceTally` moving to `Attendance/Application/Api/`. The legacy `TermAttendance` keeps its term-date handling and calls it. The dashboards get `rollCallTaken(Section, \DateTimeImmutable): bool` and `schoolToday(School, SchoolYear)` from the same API.
- Queries pass the SF2 value objects (`Sf2Report`, `Sf2Row`, `Sf2Summary`, `SectionToday`, `SectionMonth`) through as they are, entities inside them included, so the templates keep reading them unchanged; the page's own section, school and school year are Transformer arrays, as in 3c.
- `AttendanceException` becomes `App\Attendance\Domain\Exception\AttendanceException`, a Core `DomainException` (code `ATTENDANCE_REFUSED`). The busy message is Attendance's own constant with today's text.
- `AttendanceVoter` is deleted (no template asks it); `AttendancePolicy` (`TAKE`, `VIEW`, `REOPEN`) is checked by handlers and queries (a refusal is a 403), and the month page gets `canSubmit` / `canReopen` flags.

---

### Task 1: Shared pieces: roster, calendar, section label

**Files:**
- Move: `src/Attendance/{Sf2Roster,RosterEntry}.php` → `src/Learners/Application/Api/` (`Sf2Roster` becomes `SectionRoster` implementing `SectionRosterInterface`); `tests/Attendance/Sf2RosterTest.php` → `tests/Learners/Integration/Api/SectionRosterTest.php`
- Modify: `src/Schools/Application/Api/SchoolDirectory{Interface,}.php` (`schoolDays`, `monthDays`, `dayOff`, `isSchoolDay`); `src/Classes/Domain/Model/Section.php` (`getFormLabel()`); `config/deptrac.yaml` (`Learners_Api` layer; `Learners_Application` excludes `Api`); legacy users of the roster and label (`src/Nutrition/*`, `src/CardSlip/TermAttendance.php`, `src/Grading/GradeRecorder.php`, `src/Form/RollCallFormType.php`)
- Test (new): Schools API cases for the four calendar reads; a `getFormLabel()` case (with and without a grade level)

**Interfaces — Produces:** `SectionRosterInterface::forMonth(Section $section, \DateTimeImmutable $month): list<RosterEntry>`; `RosterEntry` unchanged; `Section::getFormLabel(): string`; the four `SchoolDirectoryInterface` calendar methods with `SchoolCalendar`'s signatures.

- [ ] **Step 1:** Save the HEAD route dump and a baseline copy (scratchpad).
- [ ] **Step 2:** Move and write the tests above.
- [ ] **Step 3:** Implement.
- [ ] **Step 4: Setup checks:** `cache:clear`, `lint:container`.

### Task 2: Attendance domain, repositories, SF2 report and writer

**Files:**
- Move: `src/Entity/{RollCall,AttendanceMark,LearnerMark,Sf2Submission}.php` → `src/Attendance/Domain/Model/`; `src/Repository/{RollCall,Sf2Submission}Repository.php` → `src/Attendance/Infrastructure/Repository/Doctrine*Repository.php` with interfaces in `src/Attendance/Domain/Model/Repository/` (today's public methods plus `findFresh()` for both, `add`, `remove`); `src/Attendance/AttendanceException.php` → `src/Attendance/Domain/Exception/`; `src/Attendance/{Sf2Report,Sf2Row,Sf2Summary,Sf2Status}.php` → `src/Attendance/Domain/Service/Sf2/`; `src/Attendance/Sf2ReportBuilder.php` → `src/Attendance/Application/Service/`; `src/Attendance/Sf2Writer.php` → `src/Attendance/Infrastructure/Sf2/` implementing `Domain/Service/Sf2/Sf2SpreadsheetInterface` (`xlsx(Sf2Report): string`, today's `save()` to bytes); `src/Service/SchoolsHooks/RollCallDayOffAttendance.php` → `src/Attendance/Infrastructure/Schools/`
- Modify: `config/packages/doctrine.yaml` (Attendance mapping), `config/deptrac.yaml` (Attendance layers and rules), `config/packages/twig.yaml` (`@Attendance`), `config/phpcs.xml`, `phpunit.dist.xml`; legacy users of the moved classes (scripted import rewrite); `tests/SchoolFixturesTrait.php`
- Move tests: `tests/Attendance/{Sf2ReportBuilderTest,Sf2WriterTest}.php`, `tests/Entity/RollCallTest.php`, `tests/Repository/RollCallRepositoryTest.php` → `tests/Attendance/{Integration,Unit}/...`
- Test (new): `tests/Attendance/Integration/Infrastructure/AttendanceHooksTest.php` (the day-off hook answers as before), `findFresh()` cases

- [ ] **Step 1:** Move and write the tests above.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `cache:clear`, `lint:container`, `doctrine:schema:validate`, `doctrine:migrations:diff` empty.

### Task 3: Attendance use cases, queries, policy and API

**Files:**
- Create in `src/Attendance/Application/`: `Service/AttendancePolicy.php` (today's voter rules), `Service/AttendanceScope.php` (the school's sections, the adviser's section with today's 403 message, a section of the school with today's 404), `Service/AttendanceRules.php` and `Service/AttendanceRecorder.php` (moved; no lock, no flush), `Service/AttendanceStatistics.php` (moved), `Service/{SectionToday,SectionMonth}.php` (moved), `Transformer/` for the page data
- Commands (`UseCase/Command/`) and handlers: `TakeRollCallCommand` (actor, date, marks by enrollment id), `SubmitSf2Command` (actor, month, `confirmMissing`), `ReopenSf2Command` (actor, school, section, month); each handler takes `attendance-section-<id>` first and reads the roll call or submission through `findFresh()`
- Queries (`Query/Command/`) and handlers: `GetRollCallPageCommand` (the adviser's day: refusal, counted entries, marks, counts, previous/next day), `GetAttendanceMonthCommand` (school or adviser page: report, today, previous/next month, `canSubmit`, `canReopen`, missing days), `GetAttendanceOverviewCommand` (school month: by grade, months, today, follow-ups), `GetSf2FileCommand` (file name and content)
- Create: `Application/Api/AttendanceDirectory{Interface,}.php` (`tallies`, `rollCallTaken`, `schoolToday`), `Application/Api/AttendanceTally.php` (moved from `src/CardSlip/`); legacy `TermAttendance`, `TeacherController` and `Principal\DashboardController` use it; `DemoFixtures` uses the new recorder and flushes
- Move tests: `tests/Attendance/{AttendanceRecorderTest,AttendanceStatisticsTest}.php` → `tests/Attendance/Integration/Application/` (through a flushing helper; lock and stale cases through the bus); `tests/CardSlip/TermAttendanceTest.php` stays and keeps passing
- Test (new): `AttendancePolicyTest` (today's voter cases), `AttendanceHandlersTest` (locks first and the busy answer; a page query then another request's change then the use case, without clearing the entity manager; 403/404 refusals; each query's shape), `AttendanceDirectoryTest`

- [ ] **Step 1:** Move and write the tests above.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `lint:container`.

### Task 4: Attendance screens

**Files:**
- Move: `src/Controller/{AbstractAttendanceController,SchoolAttendanceController,TeacherAttendanceController}.php` → `src/Attendance/Presentation/Controller/` (extending `BusController`, same routes, class-level attributes kept); `src/Form/{RollCallFormType,Sf2SubmitFormType}.php` → `src/Attendance/Presentation/Form/` (same block prefixes); `templates/attendance/*` → `src/Attendance/Presentation/templates/attendance/` (`@Attendance/...`); delete `src/Security/Voter/AttendanceVoter.php` and the emptied `src/Attendance/` legacy files
- Move tests: `tests/Controller/{SchoolAttendanceControllerTest,TeacherAttendanceControllerTest}.php` → `tests/Attendance/Functional/`
- Test (new): controller cases for the busy answer and for a former teacher (403 on every adviser route)

- [ ] **Step 1:** Move and write the tests.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `lint:container`, `lint:twig`, route table identical to HEAD.

### Task 5: Baselines, docs, comments, deprecations

- [ ] **Step 1:** phpcbf, then phpcs clean; LF line endings on every touched file; PHPStan on the module folders (clear cache), then baseline regeneration; deptrac 0; ESLint and Stylelint clean.
- [ ] **Step 2: Docs.** `CLAUDE.md`: the Attendance module (entities, services, its lock, the API it offers), the Learners roster API, the Schools calendar reads, `Section::getFormLabel()`, what stays legacy.
- [ ] **Step 3: Comment sweep and deprecation check** (`debug:container --deprecations`) over everything touched.

### Task 6: Verification

- [ ] **Step 1: Scoped run** of the affected tests (`tests/Attendance`, `tests/Learners`, card slip, nutrition, grading, dashboard, calendar and fixture tests), then fix, re-run only failures.
- [ ] **Step 2: Coverage** of `src/Attendance` → nothing uncovered.
- [ ] **Step 3: Full runs on the final code, one at a time:** `make test all NO_PARALLEL=1` (fresh PHPStan), `make test-feature`, `make regression-test`; schema valid, diff empty; route table identical to HEAD.
- [ ] **Step 4: Fresh whole-change review** (most capable model, read-only); fix Critical/Important (and anything a user would see) with a failing test first; full runs again.
- [ ] **Step 5: Ask the owner before the live-browser check, and whether headless or visible.** If headless: HEAD vs branch on the same dev data (reset before each side): the adviser's roll call (today, a past day, a weekend, a day off, saving marks), the month page, submitting with and without missing days, the SF2 download, the school overview (today, a month, follow-ups), a section's month as the principal, reopening, the principal's and teacher's dashboards, and a card slip; then restore the dev data.
- [ ] **Step 6: Report** and propose the commit (code and this plan); commit only after approval:

```
Move attendance into the Attendance module

Create the Attendance module with roll calls, learner marks and SF2
submissions, the SF2 report and spreadsheet, and the adviser's and
school's attendance pages, with every change through the bus. The SF2
roster moves to the Learners public API, the school calendar's reads
to the Schools API, and card slips and dashboards read attendance
through the new Attendance API. Pages, messages, URLs and downloads
are unchanged.
```
