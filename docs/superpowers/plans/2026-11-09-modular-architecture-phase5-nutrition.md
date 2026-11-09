# Modular architecture, Phase 5 (Nutrition) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create the `Nutrition` module: weighings (baseline and endline), learner measurements and SF8 submissions, the WHO classification, the SF8 report and spreadsheet, and the adviser's and school's nutrition pages. Every action goes through the Core bus, and nothing a user would notice changes.

**Architecture:** Same shape as 4a (Attendance).
- Entities lose `repositoryClass`.
- `NutritionRecorder` becomes an Application service that checks and applies a change without locking or flushing. Its use case handlers take today's `nutrition-section-<id>` key through `TransactionLockInterface` before reading anything, and read the assessment and submission fresh (`findFresh()`), because each page's query runs first in the same request.
- `NutritionVoter` becomes an Application `NutritionPolicy`.
- The SF8 builder moves to Application. The PhpSpreadsheet writer and the WHO classifier move to Infrastructure behind Domain interfaces.
- Nutrition offers no public API: no other module reads its data, they only link to its routes.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Twig, PhpSpreadsheet, PHPUnit 13, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row `Nutrition`, sections 5, 6, 8, 11).
- Owner decisions (2026-11-09): Nutrition is next.
- This ticket only migrates: regressions the move causes are fixed, existing flaws are left.
- The plan is committed with the phase.

**Branch:** `feature/modular-architecture` (4c committed as `32df040`, notes as `2ac9002`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 0. Planning | 2026-11-09 00:10:47 | 00:15:23 | 4m 36s |
| 1. Nutrition domain, repositories, classifier, SF8 report and writer | 2026-11-09 00:18:36 | 00:24:22 | 5m 46s |
| 2. Nutrition use cases, queries and policy | 00:24:22 | 00:28:46 | 4m 24s |
| 3. Nutrition screens; legacy removed | 00:28:46 | 00:31:45 | 2m 59s |
| 4. Baselines, docs, comments, deprecations | 00:31:45 | 00:44:55 | 13m 10s |
| 5. Verification | 00:44:55 | 01:25:39 | 40m 44s |

## Global Constraints

**Structure only.** These stay exactly as today:
- URLs, route names, HTTP methods and route requirements (`period` is `baseline|endline`).
- The CSRF token id `nutrition-reopen-<section id>-<period>`.
- Form block prefixes and field names: `nutrition_sheet_form[weighedOn]`, `[entries][<enrollment id>][weightKg|heightM|remarks]`, `sf8_submit_form[confirm_unweighed]`.
- Flash, form-error and refusal messages, activity-log lines, status codes and redirects.
- Access rules, including the 403 message "You can no longer weigh this section.".
- The SF8 file name, content type and `Content-Disposition` fallback.
- Table and column names. `doctrine:migrations:diff` stays empty, and the route table matches HEAD.

**Layers.**
- Nutrition may use Core, Access (Domain, Api), Schools (Domain, Api), Classes (Domain, Api) and Learners (Domain, Api).
- Legacy (the principal dashboard's shortcut) may use Nutrition's routes. Nutrition has no `Nutrition_Api` layer.

**Bus rules.**
- Controllers extend `BusController`, and handlers refuse by throwing a `DomainException`.
- Handlers that lock take the lock before any read, and read what a page query may have loaded through `findFresh()`.
- Every moved class keeps its class-level attributes: `#[IsGranted(User::ROLE_TEACHER)]` on the adviser's pages, and `#[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]` on the school's (the same `SCHOOL_MANAGE` value as today's `SchoolVoter::MANAGE`).
- Command log arrays carry ids and counts only, never typed text such as remarks.

**Time.** "Today" stays `SchoolClockInterface::now()` at midnight.

**New code.**
- `declare(strict_types=1)`.
- PHPStan level 9 with no baseline entries.
- phpcs PSR-12, lines up to 120 characters.
- 100% coverage of `src/Nutrition`, added to `<source>` in `phpunit.dist.xml`.

**Tests.**
- Moved tests keep their assertions; only the way they reach a value may change (`$row->enrollment` instead of `$row->entry->enrollment`).
- New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first), with every setup line under `// Initialize` and `// Execute` running the code under test. They use the existing helpers, and `createStub` unless the test sets expectations.
- Tests that boot the kernel go in Integration or Functional.
- After moving a test, check its class name, its `__DIR__` paths (`dirname(__DIR__, 2)` becomes `dirname(__DIR__, 4)`) and that its folder has left the legacy suite.
- In functional tests, reload an entity through its repository after a request before changing it.
- Compare ids, not entities, in assertions that may fail.

**Helper scripts.**
- Check the scratchpad first: `set_namespace.py`, `replace_text.py`, `prune_uses.py`, `wrap_long.py`, `wrap2.py`, `prune_baseline.py`, `baseline_renamed.py`, `compare_routes.py`, `to_lf.py`, `uncovered.py`, and the live-check scripts.
- Write new ones with the Write tool, in LF.
- No inline heredocs, no `sed` for text with backslashes, no `git add -N`.
- Save the HEAD route dump and a baseline copy before the first code change.

**Running checks.**
- Tests are written per task and run once in Task 5, one command at a time, in Docker (`MSYS_NO_PATHCONV=1`).
- `TEST_TARGET` takes one class, method or path.
- Read each check's output before recording it as passed.
- Clear `reports/phpstan/.cache` before a PHPStan run whose result counts.
- After fixing one checker's findings, rerun the other checkers on the same files.
- Run deptrac through `make test deptrac`.
- Long runs go to the background and are watched directly. Stop leftover servers, monitors and shells.

**PHPStan baseline.** Prune entries for deleted files and regenerate on a clear cache. The total must not grow, and no entry may name a module folder or a file not on `develop`.

**Live-browser check.**
- Ask first, and confirm headless or visible.
- Serve both sides through the `php -S` router, after moving the worktree `.superpowers/sdd/2026-11-07-modular-architecture-phase2d-schools/head` to `2ac9002`.
- Check the saved HTML isn't empty.
- Reset the dev data before each side and restore it after.

**Comments and commit.**
- Comments short and plain, with no Claude/Anthropic mention.
- One commit at the end after approval, through the Base hook, including this plan (`git add -f`).

## Review Focus

1. **Two changes at once.**
   - Saving a weighing, submitting SF8 and reopening it all wait for `nutrition-section-<id>` ("Another change is being saved. Please try again.").
   - An assessment or submission changed by another request between the page and the save is read fresh under the lock:
     - a submitted period refuses a save with "Baseline is submitted and locked.";
     - a second submit gets "Baseline is already submitted.";
     - a reopen of an unsubmitted period gets "Baseline is not submitted.";
     - a submit counts measurements another request saved after the page was read.
   - Pinned by the moved `NutritionRecorderTest` and by new handler tests. These run the page query first, then change data by SQL, then send the use case, without clearing the entity manager.
2. **Who may do what.**
   - Only the adviser weighs and submits, only for their section this school year, and not an admin who advises.
   - The adviser, the principal and admins view. The principal and admins reopen.
   - A teacher who advises no section gets 403 with "You do not advise a section this school year.".
   - Posting a sheet without the right to weigh gets 403 with "You can no longer weigh this section.".
   - A former teacher gets 403. A section of another school is a 404, and so is an unknown period.
   - Pinned by the moved controller tests and `NutritionPolicyTest`.
3. **Dates and rosters.**
   - Weighing outside the school year, in the future, an endline on or before the baseline, and a baseline on or after the endline are refused with today's messages.
   - Inputs for learners who don't count on the date are ignored. An emptied line removes its measurement.
   - A sheet posted after a learner left or joined is refused whole (`ROSTER_CHANGED`).
   - Half-filled lines are rejected by the form ("Enter both weight and height, or neither.").
   - Pinned by the moved recorder and controller tests.
4. **SF8 output.** Same classification (WHO tables, bounds, the 60–228 month range), same summary and school totals, the compare view, the same cells and added rows past 50, the same signatures and certifier, and the same file name and content type. Pinned by the moved `NutritionClassifierTest`, `Sf8ReportBuilderTest`, `Sf8WriterTest` and controller tests.
5. **Legacy users keep working.** The principal's `/principal/nutrition` shortcut, the admin school list and class list links, the teacher and principal menu highlighting, and the school profile's SF8 certifier fields. Pinned by the moved controller tests and `PrincipalAreaTest`.

## Rulings in this plan

- **No public API.** Nutrition has no public API and no hooks: no other module reads nutrition data. Enrollments and sections delete their nutrition rows through the existing `ON DELETE CASCADE`.
- **SF8 rows.** `Sf8Row` carries `Enrollment $enrollment`, `bool $male` and `string $name` instead of the Learners API's `RosterEntry`, because the Domain may not use another module's API. `_table.html.twig`, `NutritionSheet::fromReport()`, `Sf8Summary`, `Sf8Report::males()/females()` and the writer read those fields. The rendered pages and file stay the same.
- **Classifier.** `NutritionClassifier` reads the WHO tables from `resources/who` with the project dir, which only Infrastructure may inject. It moves to `Nutrition/Infrastructure/Classification/` and implements `Domain/Service/Classification/NutritionClassifierInterface::classify()`. The other public methods and constants stay on the class for its test.
- **Writer and file name.** `Sf8Writer` moves to `Nutrition/Infrastructure/Sf8/` and implements `Domain/Service/Sf8/Sf8SpreadsheetInterface::xlsx(Sf8Report): string` (today's write-and-save, to bytes). The file name moves to `Sf8Report::fileName()` with today's format, as SF2 did.
- **Exceptions and lock.**
  - `NutritionException` becomes `App\Nutrition\Domain\Exception\NutritionException`, a Core `DomainException` (code `NUTRITION_REFUSED`).
  - A new `NutritionAccessDeniedException` (code `NUTRITION_ACCESS_DENIED`) becomes a 403.
  - The busy message is `NutritionRecorder::BUSY`, with today's text.
  - The lock key is today's `nutrition-section-<id>`, now held to the end of the use case's transaction.
- **Fresh reads.** `NutritionRules::isLocked()` and the other period's date read fresh, and the recorder reads the assessment with its measurements through `findFresh()`, so the submit's unweighed count sees another request's save.
- **Section changed since the page.** Handlers check that the adviser's section is still the one the page showed, as Attendance does (`NutritionScope::ownSection()`). If it isn't, the answer is 403 "You do not advise a section this school year.", where today the change would go to the new advisory.
- **Flash after a save.** After a successful save, the controller asks the period query again for the "Saved … weights for N learners." count, as today's controller rebuilt the report after saving.
- **Page data.** Queries pass `Sf8Report` and its value objects through as they are, entities inside them included, so the templates keep reading them unchanged.
- **Voter removed.** `NutritionVoter` is deleted (no template asks it). `NutritionPolicy` (`FILL`, `VIEW`, `REOPEN`) is checked by handlers and queries, and the period query returns `canFill` and `canReopen`.

---

### Task 1: Nutrition domain, repositories, classifier, SF8 report and writer

**Files:**
- **Move:**
  - `src/Entity/{NutritionAssessment,NutritionMeasurement,Sf8Submission}.php` and `src/Nutrition/NutritionPeriod.php` → `src/Nutrition/Domain/Model/`.
  - `src/Repository/{NutritionAssessment,Sf8Submission}Repository.php` → `src/Nutrition/Infrastructure/Repository/Doctrine*Repository.php`, with interfaces in `src/Nutrition/Domain/Model/Repository/`. They keep today's `findFor`/`findForSections`, and add `findFresh(Section, NutritionPeriod)` (the assessment with its measurements, or the submission), `add` and `remove`.
  - `src/Nutrition/NutritionException.php` → `src/Nutrition/Domain/Exception/`, plus a new `NutritionAccessDeniedException`.
  - `src/Nutrition/{Sf8Report,Sf8Row,Sf8Summary,Sf8Status,NutritionResult,BmiCategory,HfaCategory}.php` → `src/Nutrition/Domain/Service/Sf8/`. `Sf8Row` gets `enrollment`, `male`, `name` in place of `entry`, and `Sf8Report` gets `fileName()`.
  - `src/Nutrition/NutritionClassifier.php` → `src/Nutrition/Infrastructure/Classification/`, implementing `Domain/Service/Classification/NutritionClassifierInterface`.
  - `src/Nutrition/Sf8Writer.php` → `src/Nutrition/Infrastructure/Sf8/`, implementing `Sf8SpreadsheetInterface`.
  - `src/Nutrition/Sf8ReportBuilder.php` → `src/Nutrition/Application/Service/`. It uses `SectionDirectoryInterface::sectionsOf()`, `SchoolDirectoryInterface::principalOf()`, the classifier interface and the repository interfaces.
- **Modify:**
  - `config/packages/doctrine.yaml` (Nutrition mapping), `config/deptrac.yaml` (four Nutrition layers with Attendance's rules minus `_Api`; the Legacy collector excludes `Nutrition\\`), `config/packages/twig.yaml` (`@Nutrition`), `config/phpcs.xml`, `phpunit.dist.xml` (`src/Nutrition` in `<source>`; `tests/Nutrition` leaves the legacy suite).
  - Legacy users of the moved classes (scripted import rewrite) and `tests/SchoolFixturesTrait.php`.
- **Move tests:**
  - `tests/Entity/NutritionEntitiesTest.php` → `tests/Nutrition/Integration/Domain/`.
  - `tests/Nutrition/Sf8ReportBuilderTest.php` → `tests/Nutrition/Integration/Application/`.
  - `tests/Nutrition/{NutritionClassifierTest,Sf8WriterTest}.php` → `tests/Nutrition/Unit/Infrastructure/`.
- **Test (new):** `findFresh()` cases (a change made by SQL after a first read is seen, for both repositories), and `Sf8Report::fileName()` with an Ñ section name.

**Interfaces — Produces:**
- `NutritionClassifierInterface::classify(bool $male, ?\DateTimeImmutable $birthDate, \DateTimeImmutable $weighedOn, ?float $weightKg, ?float $heightM): NutritionResult`
- `Sf8SpreadsheetInterface::xlsx(Sf8Report $report): string`
- `Sf8Report::fileName(): string`
- `Sf8ReportBuilder::build(Section, NutritionPeriod): Sf8Report` and `buildForSchool(School, SchoolYear, NutritionPeriod): list<Sf8Report>`
- `NutritionAssessmentRepositoryInterface::{findFor, findForSections, findFresh, add}` and `Sf8SubmissionRepositoryInterface::{findFor, findForSections, findFresh, add, remove}`

- [ ] **Step 1:** Save the HEAD route dump and a baseline copy (scratchpad).
- [ ] **Step 2:** Move and write the tests above.
- [ ] **Step 3:** Implement.
- [ ] **Step 4: Setup checks:** `cache:clear`, `lint:container`, `doctrine:schema:validate`, `doctrine:migrations:diff` empty.

### Task 2: Nutrition use cases, queries and policy

**Files:**
- **Create in `src/Nutrition/Application/`:**
  - `Service/NutritionPolicy.php`: today's voter rules, with `can()` and `deny($user, $attribute, $section, $message = 'Access Denied.')`.
  - `Service/NutritionScope.php`: account, school, current year, a section of the school (404), the adviser's section (403 `NO_ADVISORY`), `pageSection()` and `ownSection()`, as `AttendanceScope` does.
  - `Service/NutritionRules.php` and `Service/NutritionRecorder.php`, moved: no lock, no flush, repository `add`/`remove`, fresh reads, and `LOCK = 'nutrition-section-'` and `BUSY` constants.
  - `UseCase/Command/MeasurementInput.php`, moved from `src/Nutrition/`.
- **Commands** (`UseCase/Command/`) and handlers. Each handler takes `NutritionRecorder::LOCK . <section id>` first:
  - `SaveWeighingCommand(actorId, sectionId, period, weighedOn, inputs)`. It returns nothing, and its log array has the input count only. Denies `FILL` with "You can no longer weigh this section.".
  - `SubmitSf8Command(actorId, sectionId, period, confirmUnweighed)`. Denies `FILL`.
  - `ReopenSf8Command(actorId, schoolId, sectionId, period)`. Denies `REOPEN`.
- **Queries** (`Query/Command/`) and handlers:
  - `GetNutritionIndexCommand(actorId)`: the adviser's section and both periods' reports.
  - `GetNutritionPeriodCommand(actorId, ?schoolId, ?sectionId, period)`: section, report, `canFill`, `canReopen`, unweighed count.
  - `GetNutritionOverviewCommand(actorId, schoolId, period, compare)`: school, school year, reports, endlines by section id, total, endline total.
  - `GetSf8FileCommand(actorId, ?schoolId, ?sectionId, period)`: file name and content.
- **Move tests:**
  - `tests/Nutrition/NutritionRecorderTest.php` → `tests/Nutrition/Integration/Application/`, through a flushing helper.
  - `tests/Security/NutritionVoterTest.php` → `tests/Nutrition/Integration/Application/NutritionPolicyTest.php`, asking `NutritionPolicy::can()`.
- **Test (new):** `NutritionHandlersTest`:
  - each handler takes its lock first and answers busy while it is held;
  - a page query, then another request's change by SQL, then the use case, without clearing the entity manager, for submitted-then-save, double submit, reopen of a reopened period, and submit after a measurement was added;
  - 403/404 refusals, including a changed advisory;
  - each query's shape.

  Also a unit test that the commands' log arrays carry no remarks text.

- [ ] **Step 1:** Move and write the tests above.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `lint:container`.

### Task 3: Nutrition screens; legacy removed

**Files:**
- **Move:**
  - `src/Controller/{AbstractNutritionController,SchoolNutritionController,TeacherNutritionController}.php` → `src/Nutrition/Presentation/Controller/`. They extend `BusController` with the same routes, and keep their class-level attributes, `MapQueryParameter`, the period enum argument and the reopen CSRF expression.
  - `src/Form/{NutritionEntry,NutritionEntryFormType,NutritionSheet,NutritionSheetFormType,Sf8SubmitFormType}.php` → `src/Nutrition/Presentation/Form/`, with the same block prefixes.
  - `templates/nutrition/*` → `src/Nutrition/Presentation/templates/nutrition/` (`@Nutrition/...`), with `row.male`, `row.name`, `row.enrollment` in `_table.html.twig`.
- **Delete:** `src/Security/Voter/NutritionVoter.php` and the emptied legacy `src/Nutrition/*.php` files.
- **Move tests:** `tests/Controller/{SchoolNutritionControllerTest,TeacherNutritionControllerTest}.php` → `tests/Nutrition/Functional/`.
- **Test (new):** controller cases for the busy answer on save, and for a former teacher (403 on every adviser route).

- [ ] **Step 1:** Move and write the tests.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `cache:clear`, `lint:container`, `lint:twig`, and the route table identical to HEAD.

### Task 4: Baselines, docs, comments, deprecations

- [ ] **Step 1: Static checks.**
  - phpcbf, then phpcs clean.
  - LF line endings on every touched file.
  - PHPStan on the module folders (cache cleared), then on everything; prune the baseline and check it with `baseline_renamed.py`.
  - deptrac 0 violations and 0 uncovered.
  - ESLint and Stylelint clean.
- [ ] **Step 2: Docs.** In `CLAUDE.md`: the Nutrition module (entities, services, its lock, policy, SF8 and classifier placement, no public API), and Nutrition removed from what stays legacy.
- [ ] **Step 3: Comment sweep and deprecation check** (`debug:container --deprecations`) over everything touched.

### Task 5: Verification

- [ ] **Step 1: Scoped run** of the affected tests (`tests/Nutrition`, `PrincipalAreaTest`, and the Schools profile and admin school list tests), then fix and re-run only the failures.
- [ ] **Step 2: Coverage** of `src/Nutrition`: nothing uncovered.
- [ ] **Step 3: Guard break.** Remove the lock from the save handler once and watch the busy test fail.
- [ ] **Step 4: Full runs on the final code, one at a time:** `make test all` (fresh PHPStan), `make test-feature`, `make regression-test`. Schema valid, diff empty, route table identical to HEAD.
- [ ] **Step 5: Fresh whole-change review** (most capable model, read-only). Fix Critical and Important findings (and anything a user would see) with a failing test first, then do the full runs again.
- [ ] **Step 6: Ask the owner before the live-browser check, and whether headless or visible.** If headless, compare HEAD and the branch on the same dev data, reset before each side. Cover:
  - the adviser's index, the baseline sheet, a refused date, a half-filled line, saving, submitting with and without the confirm, and the SF8 download;
  - the school overview (baseline, endline, compare), a section's read-only page, reopening, and the school's SF8 download;
  - the principal shortcut, and an admin's overview.

  Then restore the dev data.
- [ ] **Step 7: Report** and propose the commit (code and this plan). Commit only after approval:

```
Move nutrition into the Nutrition module

Create the Nutrition module with weighings, measurements and SF8
submissions, the WHO classification, the SF8 report and spreadsheet,
and the adviser's and school's nutrition pages, with every change
through the bus under the section's lock. Pages, messages, URLs and
downloads are unchanged.
```
