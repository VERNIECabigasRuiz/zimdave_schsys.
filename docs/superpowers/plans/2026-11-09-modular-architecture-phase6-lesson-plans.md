# Modular architecture, Phase 6 (Lesson plans) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create the `LessonPlans` module: the weekly ILAW lesson plan, its week days, its PDF and the teacher's and principal's lesson plan pages. Every action goes through the Core bus, and nothing a user would notice changes.

**Architecture:** Same patterns as Phases 4 and 5.
- The `LessonPlan` entity loses `repositoryClass` and its validator attribute. The topic's length rule moves to the form's data object, which the edit form now binds to instead of the entity.
- `LessonWeek` becomes an Application service. It reads a load's timetable through a new Classes API method and the school's days off through the Schools API.
- `LessonPlanVoter` becomes an Application `LessonPlanPolicy`.
- The PDF is split in two:
  - an Application `LessonPlanPrinter` builds a Domain `LessonPlanPrintout`: the plan, the bullets, both signatories and the file name;
  - an Infrastructure renderer turns it into HTML and PDF behind a Domain interface.
- The signatory's position comes from the service record, which Staff owns and which is still legacy. It reaches Lesson plans through a hook interface in `LessonPlans/Domain/Service/`, implemented in legacy for now.
- Lesson plans offer no public API.
- No change takes a lock, as today.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Twig, Dompdf, PHPUnit 13, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row `LessonPlans`, sections 5, 6, 8, 11).
- Lesson plans follow Nutrition in the spec's order.
- This ticket only migrates: regressions the move causes are fixed, existing flaws are left.
- The plan is committed with the phase.

**Branch:** `feature/modular-architecture` (Phase 5 committed as `62b0ee8`, notes as `e6e4874`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 0. Planning | 2026-11-09 01:36:08 | 01:39:19 | 3m 11s |
| 1. Lesson plan domain, repository, week, printout and PDF | 2026-11-09 01:44:47 | 01:49:01 | 4m 14s |
| 2. Lesson plan use cases, queries and policy | 01:49:01 | 01:52:42 | 3m 41s |
| 3. Lesson plan screens; legacy removed | 01:52:42 | 01:54:08 | 1m 26s |
| 4. Baselines, docs, comments, deprecations | 01:54:08 | 02:04:31 | 10m 23s |
| 5. Verification | 02:04:31 | 03:15:52 | 1h 11m 21s |

## Global Constraints

**Structure only.** These stay exactly as today:
- URLs, route names, HTTP methods and route requirements.
- The CSRF token id `lesson-plan-delete-<id>`.
- Form names and fields: `lesson_plan_start_form[load|week]`, and `lesson_plan_form[topic|competencies|classNotes|days][..][<field>]|references|aiDeclaration|save]`.
- Flash and form-error messages, status codes, redirects and access rules. That includes the principal's "not assigned" page and the 422 when the start form names another teacher's class.
- The PDF content, page size, file name and ASCII fallback.
- Table and column names. `doctrine:migrations:diff` stays empty, and the route table matches HEAD.

**Layers.**
- Lesson plans may use Core, Access (Domain, Api), Schools (Domain, Api) and Classes (Domain, Api).
- Legacy may use Lesson plans: the demo fixtures, and the service-record hook implementation.
- There is no `LessonPlans_Api` layer.

**Bus rules.**
- Controllers extend `BusController`, and handlers refuse by throwing a `DomainException`.
- Every moved class keeps its class-level attributes: `#[IsGranted(User::ROLE_TEACHER)]` on the teacher's controller, and `#[IsGranted(User::ROLE_PRINCIPAL)]` on the principal's list.
- Command log arrays carry ids and counts only, never typed text such as the topic or the plan's cells.

**Time.** "Now" stays `SchoolClockInterface::now()`.

**New code.**
- `declare(strict_types=1)`.
- PHPStan level 9 with no baseline entries.
- phpcs PSR-12, lines up to 120 characters.
- 100% coverage of `src/LessonPlans`, added to `<source>` in `phpunit.dist.xml`.
- Domain entities write column types as strings.

**Tests.**
- Moved tests keep their assertions; only the way they reach a value may change.
- New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first), with every setup line under `// Initialize` and `// Execute` running the code under test.
- They use the existing helpers, and `createStub` unless the test sets expectations.
- Kernel tests go in Integration or Functional.
- After moving a test, check its class name, its `__DIR__` paths and that its folder has left the legacy suite, and grep config for any folder removed.
- Compare ids, not entities, in assertions that may fail.

**Moving classes.**
- After moving classes, grep their short names, and grep templates and config for their full old names.
- After a scripted import rewrite, check for repeated and same-namespace imports.

**Helper scripts.**
- Check the scratchpad first: `move5_nutrition.py`, `dedupe_uses.py`, `set_namespace.py`, `replace_text.py`, `prune_uses.py`, `wrap_long.py`, `wrap2.py`, `prune_baseline.py`, `compare_routes.py`, `to_lf.py`, `uncovered.py`, `worktree_package.sh`, and the live-check scripts.
- Write new ones with the Write tool, in LF.
- No inline heredocs, no `sed` for text with backslashes, no `git add -N`.
- Save the HEAD route dump and a baseline copy before the first code change.

**Running checks.**
- Tests are written per task and run once in Task 5, one command at a time, in Docker (`MSYS_NO_PATHCONV=1`).
- `TEST_TARGET` takes one class, method or path.
- Read each check's output before recording it as passed.
- Clear `reports/phpstan/.cache` before a PHPStan run whose result counts.
- After fixing one checker's findings, rerun the others on the same files.
- Run deptrac through `make test deptrac`.
- Long runs go to the background and are watched directly. Compute durations with a command.

**PHPStan baseline.** Prune entries for deleted files and regenerate on a clear cache. The total must not grow, and no entry may name a module folder or a file not on `develop`.

**Live-browser check.**
- Ask first, and confirm headless or visible.
- Move the worktree `.superpowers/sdd/2026-11-07-modular-architecture-phase2d-schools/head` to `e6e4874`.
- Serve both sides through the `php -S` router, and check the saved HTML isn't empty.
- Reset the dev data before each side and restore it after.

**Comments and commit.**
- Comments short and plain, with no Claude/Anthropic mention.
- One commit at the end after approval, through the Base hook, including this plan (`git add -f`).

## Review Focus

1. **Who may do what.**
   - Only the plan's teacher opens, edits and deletes it.
   - The teacher and their school's principal this school year view it and download its PDF.
   - Another teacher and another school's principal get 403, and an unknown plan is a 404.
   - The start form offers only the teacher's own classes; posting another teacher's class answers 422 and saves nothing.
   - A principal with no school this year sees the "not assigned" page.
   - Pinned by the moved controller tests and the new `LessonPlanPolicyTest`.
2. **Starting a plan.**
   - The days follow the timetable for the week's semester, or the subject's meetings per week from Monday without a timetable.
   - A school day off stays as a holiday.
   - Starting the same class and week again opens the existing plan; any day of the week starts on its Monday.
   - The redirect goes to the plan's edit page.
   - Pinned by the moved `LessonWeekTest` and controller tests, and the new handler tests.
3. **Saving.**
   - Every field is trimmed as before, blank optional fields become null, and holiday days keep their date and reason with no cells.
   - A blank topic or blank competencies, or a topic over 200 characters, re-renders with 422 and today's messages.
   - The plan's "updated" time moves forward.
   - Pinned by the moved `testSaveEdits` and new handler and validation cases.
4. **The PDF.**
   - The same blocks, days, bullets and signatories: the teacher, and the school's principal this year, each with their current service-record designation, or "Teacher" / "School Principal" without one.
   - The same letter-landscape page, file name and ASCII fallback.
   - Pinned by the moved PDF tests and a new hook test.
5. **Legacy users keep working.** The demo fixtures' lesson plan, the school profile's "Lesson plans checked by", and the teacher's and principal's menus. Pinned by `DemoFixturesTest`, `SchoolProfileTest`, `TeacherAreaTest` and `PrincipalAreaTest`.

## Rulings in this plan

- **Module name.** It is `LessonPlans`, as the spec names it: `src/LessonPlans`, `@LessonPlans`, `tests/LessonPlans`. The legacy `src/LessonPlan` folder goes.
- **Edit form.** It binds to a Presentation data object, `LessonPlanContent` (topic, competencies, class notes, days, references, AI declaration), with today's form constraints plus the entity's `Length(max: 200)` on the topic. `SaveLessonPlanCommand` carries those values, and the entity's setters keep doing the trimming.
- **Start redirect.** A create use case can't return the new id (it exists only once the bus commits), so after `StartLessonPlanCommand` the controller asks `GetLessonPlanIdCommand` (load, week) for the redirect.
- **Signatory position.** It comes through `LessonPlans/Domain/Service/Signatory/SignatoryPositionsInterface::positionOf(User): ?string`. For now it is implemented in legacy (`src/ServiceRecord/LessonPlanSignatoryPositions.php`, reading `ServiceRecordEntryRepository::findCurrent()`), and moves to Staff with the service record.
- **Timetable read.** The Classes API gains `TeachingLoadDirectoryInterface::scheduleOf(TeachingLoad): list<ScheduleEntry>` (today's `findForLoad`). Days off come from `SchoolDirectoryInterface::daysOffBetween()`.
- **PDF file name.** `LessonPlanPrintout::fileName()` keeps today's format. The ASCII fallback (Symfony String) is built in the controller, because Domain and Application may not use the framework.
- **No locks.** Two first starts of the same class and week at once still end on the unique index, as today.
- **Voter removed.** `LessonPlanVoter` is deleted (no template asks it). `LessonPlanPolicy` (`VIEW`, `EDIT`) is checked by handlers and queries, and a refusal is a 403.

---

### Task 1: Lesson plan domain, repository, week, printout and PDF

**Files:**
- **Move:**
  - `src/Entity/LessonPlan.php` → `src/LessonPlans/Domain/Model/`, with column types as strings and no validator attribute.
  - `src/Repository/LessonPlanRepository.php` → `src/LessonPlans/Infrastructure/Repository/DoctrineLessonPlanRepository.php`, with `Domain/Model/Repository/LessonPlanRepositoryInterface.php`. It keeps `findForTeacher`, `findForSchool` and `findOneFor`, and adds `findById`, `add` and `remove`.
  - `src/LessonPlan/LessonWeek.php` → `src/LessonPlans/Application/Service/`, using `TeachingLoadDirectoryInterface::scheduleOf()` and `SchoolDirectoryInterface::daysOffBetween()`.
  - `src/LessonPlan/LessonPlanPdf.php` → `src/LessonPlans/Infrastructure/Pdf/LessonPlanPdf.php`, implementing `Domain/Service/Pdf/LessonPlanPdfInterface` (`render(LessonPlanPrintout): string`, `html(LessonPlanPrintout): string`). It renders `@LessonPlans/lesson_plans/pdf.html.twig` with today's variables.
- **Create:**
  - `Domain/Service/Pdf/LessonPlanPrintout.php` (readonly): plan, school, competencies, references, teacher and principal signatories, and `fileName()`.
  - `Application/Service/LessonPlanPrinter.php`: `printout(LessonPlan): LessonPlanPrintout`, reading the current year, the school's principal and the signatory positions.
  - `Domain/Service/Signatory/SignatoryPositionsInterface.php`, and its legacy implementation in `src/ServiceRecord/`.
  - `src/Classes/Application/Api/TeachingLoadDirectory{Interface,}.php`: `scheduleOf`.
- **Modify:**
  - `config/packages/doctrine.yaml` (LessonPlans mapping).
  - `config/deptrac.yaml`: four LessonPlans layers placed beside the other modules, with only the dependencies they use. The Legacy collector excludes `LessonPlans\\`, and Legacy may use the four layers.
  - `config/packages/twig.yaml` (`@LessonPlans`), `config/phpcs.xml`.
  - `phpunit.dist.xml`: `src/LessonPlans` in `<source>`, and `tests/LessonPlan` leaves the legacy suite.
  - Legacy users of the moved classes (scripted import rewrite): `DemoFixtures`, `tests/SchoolFixturesTrait.php`.
- **Move tests:**
  - `tests/LessonPlan/LessonPlanEntityTest.php` → `tests/LessonPlans/Unit/Domain/`.
  - `tests/LessonPlan/LessonWeekTest.php` → `tests/LessonPlans/Integration/Application/`.
- **Test (new):**
  - the repository's `findById`/`add`/`remove`;
  - `scheduleOf` in the Classes API test;
  - the legacy hook (current designation, none);
  - `LessonPlanPrinter` (signatories with and without designations, no principal, the file name with "/" and "%");
  - the PDF renderer's page size.

**Interfaces — Produces:**
- `LessonWeek::monday(\DateTimeImmutable): \DateTimeImmutable` (static), `start(TeachingLoad, \DateTimeImmutable, \DateTimeImmutable): LessonPlan`, `days(TeachingLoad, \DateTimeImmutable): list<array{date: string, holiday: ?string}>`
- `LessonPlanPrinter::printout(LessonPlan): LessonPlanPrintout`
- `LessonPlanPdfInterface::{render, html}(LessonPlanPrintout): string`
- `LessonPlanRepositoryInterface::{findById, findForTeacher, findForSchool, findOneFor, add, remove}`

- [ ] **Step 1:** Save the HEAD route dump and a baseline copy (scratchpad).
- [ ] **Step 2:** Move and write the tests above.
- [ ] **Step 3:** Implement.
- [ ] **Step 4: Setup checks:** `cache:clear`, `lint:container`, `doctrine:schema:validate`, `doctrine:migrations:diff` empty.

### Task 2: Lesson plan use cases, queries and policy

**Files:**
- **Create in `src/LessonPlans/Application/`:**
  - `Service/LessonPlanPolicy.php`: today's voter rule. The teacher may do anything; the school's principal this year may `VIEW`.
  - `Service/LessonPlanScope.php`: the account; the plan (404 `LessonPlanNotFoundException`); the teacher's school this year; the teacher's loads there.
  - `Domain/Exception/LessonPlan{NotFound,AccessDenied}Exception.php`.
- **Commands** (`UseCase/Command/`) and handlers:
  - `StartLessonPlanCommand(actorId, loadId, week)`: the load must be one of the actor's loads this year at their school (403 otherwise). It opens the existing plan or starts one.
  - `SaveLessonPlanCommand(actorId, planId, topic, competencies, classNotes, references, aiDeclaration, days)`: denies `EDIT`, applies the setters, then `touch`es the plan.
  - `DeleteLessonPlanCommand(actorId, planId)`: denies `EDIT`.
- **Queries** (`Query/Command/`) and handlers:
  - `GetTeacherLessonPlansCommand(actorId)`: the teacher's plans this school year (none without one).
  - `GetLessonPlanStartCommand(actorId)`: the teacher's loads at their school this year, and today.
  - `GetLessonPlanIdCommand(actorId, loadId, week)`: the id of the actor's plan for that load and week.
  - `GetLessonPlanCommand(actorId, planId, attribute)`: the plan after the 404/403 check.
  - `GetSchoolLessonPlansCommand(actorId)`: the principal's school this year (null when not assigned), the school year, and the plans.
  - `GetLessonPlanPdfCommand(actorId, planId)`: content and file name.
- **Test (new):**
  - `LessonPlanPolicyTest`: the teacher, the school's principal, another school's principal, another teacher, an admin, and last year's principal.
  - `LessonPlanHandlersTest`: start new and existing; start for another teacher's load (403); save trims and nulls, keeps holidays, and moves "updated"; delete; save or delete by another teacher (403); an unknown plan (404); each query's shape.
  - `LessonPlanCommandLogTest`: no typed text in the log arrays.

- [ ] **Step 1:** Write the tests above.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `lint:container`.

### Task 3: Lesson plan screens; legacy removed

**Files:**
- **Move:**
  - `src/Controller/{TeacherLessonPlanController,LessonPlanViewController}.php` → `src/LessonPlans/Presentation/Controller/`. They extend `BusController` with the same routes and class or method attributes. Delete keeps its `LessonPlan $plan` argument for the CSRF expression.
  - `src/Form/{LessonPlanStartFormType,LessonPlanFormType,LessonPlanDayFormType}.php` → `src/LessonPlans/Presentation/Form/`, plus the new `LessonPlanContent`. Same block prefixes.
  - `templates/lesson_plans/*` → `src/LessonPlans/Presentation/templates/lesson_plans/` (`@LessonPlans/...`).
- **Delete:** `src/Security/Voter/LessonPlanVoter.php` and the emptied legacy files and folders (`src/LessonPlan`, `tests/LessonPlan`). Grep config for them first.
- **Move tests:** `tests/Controller/{TeacherLessonPlanControllerTest,LessonPlanViewControllerTest}.php` → `tests/LessonPlans/Functional/`. The PDF cases reach the HTML through the printer and the renderer.
- **Test (new):**
  - a topic over 200 characters re-renders with 422;
  - a former teacher gets 403 on every teacher route;
  - a principal of no school sees the "not assigned" page;
  - an unknown plan is a 404.

- [ ] **Step 1:** Move and write the tests.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `cache:clear`, `lint:container`, `lint:twig`, and the route table identical to HEAD.

### Task 4: Baselines, docs, comments, deprecations

- [ ] **Step 1: Static checks.**
  - phpcbf, then phpcs clean.
  - LF line endings on every touched file.
  - PHPStan on the module folders (cache cleared), then on everything; prune the baseline and confirm it only shrank.
  - `make test deptrac`: 0 violations and 0 uncovered.
  - ESLint and Stylelint clean.
- [ ] **Step 2: Docs.** In `CLAUDE.md`: the LessonPlans module (entity, services, policy, PDF placement, the signatory hook implemented in legacy, no API, no locks), and the Classes API's `scheduleOf`.
- [ ] **Step 3: Comment sweep and deprecation check** (`debug:container --deprecations`) over everything touched.

### Task 5: Verification

- [ ] **Step 1: Scoped run** of the affected tests (`tests/LessonPlans`, the Classes API test, `SchoolProfileTest`, `DemoFixturesTest`, `TeacherAreaTest`, `PrincipalAreaTest`, `DompdfRendererTest`), then fix and re-run only the failures.
- [ ] **Step 2: Coverage** of `src/LessonPlans`: nothing uncovered.
- [ ] **Step 3: Guard break.** Remove the save handler's `EDIT` check once and watch the other-teacher test fail.
- [ ] **Step 4: Full runs on the final code, one at a time:** `make test all` (fresh PHPStan), `make test-feature`, `make regression-test`. Schema valid, diff empty, route table identical to HEAD.
- [ ] **Step 5: Fresh whole-change review** (most capable model, read-only). Fix Critical and Important findings (and anything a user would see) with a failing test first, then do the full runs again.
- [ ] **Step 6: Ask the owner before the live-browser check, and whether headless or visible.** If headless, compare HEAD and the branch on the same dev data, reset before each side. Cover:
  - the teacher's list, starting a plan (new, and the same week again), a refused save (blank topic), saving, the PDF and deleting;
  - the principal's list, viewing a plan and its PDF;
  - another teacher's 403.

  Then restore the dev data.
- [ ] **Step 7: Report** and propose the commit (code and this plan). Commit only after approval:

```
Move lesson plans into the LessonPlans module

Create the LessonPlans module with the weekly ILAW lesson plan, its
week days, its PDF and the teacher's and principal's lesson plan
pages, with every change through the bus. The timetable and days off
come through the Classes and Schools APIs, and the signatory's
position through a hook the service record implements. Pages, URLs
and downloads are unchanged.
```
