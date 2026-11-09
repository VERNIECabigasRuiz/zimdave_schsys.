# Modular architecture, Phase 7b (Staff: service record and staff ID) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the `Staff` module. Move in:
- the service record: the principal's pages, the BP number, the rows and the PDF;
- the staff ID: `/my-id`, its details and the Big/Small PDFs;
- the lesson plans' signatory-position hook.

Every change goes through the Core bus, and nothing a user would notice changes.

**Architecture:** Same patterns as Phases 4–7a.
- **Validation off the entities.** The entities lose `repositoryClass` and their validator attributes. The row form binds to `SaveServiceRowCommand`, which carries the row's constraints. The details form binds to `SaveStaffIdDetailsCommand`.
- **The row-dates rule.** `ValidServiceRow` needs the person's other rows, so it becomes a Presentation constraint set on the row form, like Classes' `NoOverlappingPeriod`.
- **PDFs.** Both are split the LessonPlans/Learners way:
  - The Application builds a value object: `ServiceRecordPrinter` builds a `ServiceRecordPrintout`, and `StaffIdCardBuilder` builds a `StaffIdCard`.
  - Infrastructure renders it behind a Domain interface. Images come through `StaffIdImagesInterface`.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Twig, Dompdf, GD, PHPUnit 13, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row `Staff`, sections 5, 6, 8, 11).
- **Owner decisions:**
  - 2026-11-09: 7b covers the service record and the staff ID, including the signatory hook.
  - Phase 6: `LessonPlanSignatoryPositions` moves with Staff.
- **Scope:** this ticket only migrates. Regressions the move causes are fixed; existing flaws are left.
- The plan is committed with the phase.

**Branch:** `feature/modular-architecture` (7a committed as `833594e`, notes as `832d2fa`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 0. Planning | 2026-11-09 08:05:07 | 08:09:43 | 4m 36s |
| 1. Domain, repositories, PDFs, images, signatory hook | 2026-11-09 08:10:13 | 08:19:47 | 9m 34s |
| 2. Use cases, queries and builders | 08:19:47 | 08:26:00 | 6m 13s |
| 3. Service record and My ID screens; legacy removed | 08:26:00 | 08:30:32 | 4m 32s |
| 4. Baselines, docs, comments, deprecations | 08:30:32 | 08:44:59 | 14m 27s |
| 5. Verification | 08:44:59 | 10:01:23 | 1h 16m 24s |

## Global Constraints

**Structure only.** These stay exactly as today:
- URLs, route names, HTTP methods and route requirements (`entry`, `size` = `big|small`).
- Form names and field names (`service_record_entry_form[...]`, `staff_id_details_form[...]`, the `bp_number` field and the CSRF ids `service-record-bp`, `service-row-<id>`).
- Validation messages, the field each error sits on, and the number of errors per field.
- Flash messages, redirects and status codes.
- The not-found messages: "No current school year.", "This person does not belong to the school this school year.", "No such row in this service record."
- The 403 message: "Only teachers and principals with a school this school year have an ID here."
- Which check wins when several fail. The route, `IsGranted` and `IsCsrfTokenValid` attributes stay as they are, so their order does too, and the person and row 404s come after them.
- Row drafting (from the last row, or the school's city and National), closing the open row, the BP number's 30-character cut, and blank-to-null trimming.
- Both PDFs' content and file names, including the "Certified correct" fallback to the principal and the ID's signing head and its signature rule.
- The "Missing in your/their PDS" list, and the My ID warnings and print gate.
- Table and column names. `doctrine:migrations:diff` stays empty, and the route table matches HEAD.

**Layers.**
- Staff may use Core, Access (Domain, Api), Schools (Domain, Api), Learners (Domain: `SexAtBirth`) and LessonPlans_Domain. The last is for Infrastructure only: it implements `SignatoryPositionsInterface`.
- Staff never uses another module's repositories.
- The Schools API gains:
  - `assignmentOf(User, SchoolYear): ?SchoolAssignment`, wrapping `findForUser`;
  - `divisionOf(School): ?Division`, wrapping `findForSchool`.
- Legacy may use Staff: `principal_service_record` in the principal dashboard, the `has_id` Twig function, and the layouts' links.

**Bus rules.**
- Controllers extend `BusController`.
- Moved controllers keep their attributes exactly:
  - the class-level `/schools/{id}/service-records/{employee}` route with its requirements;
  - `#[IsGranted(ServiceRecordVoter::VIEW|EDIT, 'school')]` on a `School $school` argument;
  - `#[IsCsrfTokenValid(...)]`;
  - My ID's `#[IsGranted('IS_AUTHENTICATED')]` and `/my-id` prefix.
- Command log arrays carry ids, the size and booleans, never names, numbers or salaries.

**New code.**
- `declare(strict_types=1)`.
- PHPStan level 9 with no baseline entries.
- phpcs PSR-12, lines up to 120 characters.
- 100% coverage of `src/Staff`.
- Domain entities write column types as strings and carry no validator attributes.

**Tests.**
- Moved tests keep their assertions; only the way they reach a value may change.
- Row validation cases validate a `SaveServiceRowCommand` with the form's constraint, with the same property paths and messages.
- New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first), with every setup line under `// Initialize`.
- Use the existing helpers; check `SchoolFixturesTrait` before naming a helper.
- Kernel tests go in Integration or Functional.
- After moving a test, check its class name, `__DIR__` paths and suite. Take `tests/ServiceRecord` and `tests/StaffId` out of the legacy suite and grep config for them.

**Moving classes and helper scripts.**
- After moving classes, grep their short names, and grep templates and config for their full old names.
- Check for repeated and same-namespace imports.
- Check the scratchpad for helper scripts first (`move6_lesson_plans.py`, `dedupe_uses.py`, `prune_uses.py`, `set_namespace.py`, `replace_text.py`, `wrap_long.py`, `prune_baseline.py`, `compare_routes.py`, `to_lf.py`, `uncovered.py`, the live-check scripts). Write new ones with the Write tool, in LF.
- No inline heredocs, no `<<`, no `sed` for text with backslashes, no `git add -N`.
- Save the HEAD route dump and a baseline copy before the first code change.

**Running checks.**
- Tests are written per task and run once in Task 5, one command at a time, in Docker (`MSYS_NO_PATHCONV=1`). `TEST_TARGET` takes one class, method or path.
- Read each check's output.
- Clear `reports/phpstan/.cache` before a PHPStan run that counts.
- After fixing one checker's findings, rerun the others.
- Run deptrac through `make test deptrac`.
- Watch long runs directly.
- Log a task's end and the next task's start together. Compute durations with a command.

**PHPStan baseline.** It may only shrink. No entry may name a module folder.

**Live-browser check.**
- Headless, without asking (owner, 2026-11-09).
- Move the worktree `.superpowers/sdd/2026-11-07-modular-architecture-phase2d-schools/head` to `832d2fa`.
- Start servers without any removal in the same command.
- Reset the dev data before each side and restore it after.

**Comments and commit.**
- Comments short and plain, with no Claude/Anthropic mention.
- One commit at the end after approval, through the Base hook, including this plan (`git add -f`).

## Review Focus

1. **Row validation parity.**
   - A row is refused exactly as today, with the same message on the same field: from date missing; end before start; leave without pay given only half or backwards; overlap with another row ("from mm/dd/yyyy"); a second open row; the length, salary and branch limits.
   - Ticking "close the current row" lets a later row in, and the open row is then ended the day before.
   - Editing a row ignores itself.
   - Blank text fields count as empty, as today: the form trims them.
   - Pinned by the moved `ServiceRecordEntryTest` cases and the controller tests.
2. **Who may do what.**
   - Only the school's principal this year keeps records, their own included. The admin only prints. A teacher, another school's principal, or someone from another school is refused with today's code: 403 or 404.
   - Delete and BP answer a bad token from another school's principal with the same status as HEAD.
   - Pinned by `ServiceRecordControllerTest`.
3. **The page after a refused save.**
   - The row form shows what was typed, with "Add"/"Edit" in the title as today.
   - My ID after a refused save (a too-long field) shows the typed big name on the card, as today: the legacy form edited the loaded details in memory.
   - New tests pin both.
4. **PDFs.** Both print as today: header from the PDS, BP number, rows, signatories and "Certified correct" fallback; the ID's name, call name, numbers, head and signature rule, and the Big/Small layout and file names.
   - Pinned by the moved PDF and builder tests and the controller downloads.
5. **Outside users keep working.**
   - The lesson plan signatory's designation (`LessonPlanSignatoryPositionsTest`, `LessonPlanPrinterTest`, `LessonPlanViewControllerTest`).
   - `has_id` and the My ID links (`TeacherAreaTest`, `PrincipalAreaTest`).
   - The principal's "My Service Record" menu, and the teachers and admin school pages' record links (`SchoolTeacherControllerTest`, `SchoolProfileTest`).

## Rulings in this plan

- **Homes.**
  - **Entities.** `ServiceRecord`, `ServiceRecordEntry` and `StaffIdDetails` go to `Staff/Domain/Model`. The `STATUSES` and `BRANCHES` constants stay on `ServiceRecordEntry`.
  - **Repository interfaces,** in `Staff/Domain/Model/Repository/`:
    - `ServiceRecordRepositoryInterface` (`findFor`, `add`);
    - `ServiceRecordEntryRepositoryInterface` (`findById`, `findForEmployee`, `findCurrent`, `add`, `remove`);
    - `StaffIdDetailsRepositoryInterface` (`findFor`, `add`).
- **Access.**
  - `ServiceRecordVoter` moves to `Staff/Presentation/Security/` with the same attribute values, reading through `SchoolDirectoryInterface`. It stays on the controller, so the order of the school 404, the access check and the CSRF check is unchanged.
  - Handlers check the person and the row (404s), through `StaffScope::schoolEmployee()` (any role at the school this year) and `StaffScope::serviceRow()`.
  - My ID's "teacher or principal with a school" check is `StaffScope::idHolder()` (403, `StaffAccessDeniedException`).
- **Row form.**
  - `SaveServiceRowCommand` is used for both add and edit (`entryId` null when adding). It holds the row's fields under the entity's names, plus `closeOpen`, with the entity's constraints, and has `fromEntry()` and `applyTo()`.
  - `ValidServiceRow` and its validator move to `Staff/Presentation/Validation/`. The validator works on the command against the stored rows, skipping `entryId`, and the form sets it as a constraint.
  - `ServiceRecordKeeper` (Application) keeps `draft()` and the close-the-open-row step.
- **Use cases:** `SaveBpNumberCommand`, `SaveServiceRowCommand`, `DeleteServiceRowCommand`, `SaveStaffIdDetailsCommand`.
- **Queries:**
  - `GetServiceRecordCommand`: the show page.
  - `GetServiceRowFormCommand`: the prefilled command, the employee, and whether the open row can be closed.
  - `GetServiceRecordPdfCommand`: content and file names.
  - `GetMyIdCommand`: card, missing, can print, role, division and school. It takes the typed call name after a refused save.
  - `GetStaffIdPdfCommand`: content and file name, or `StaffIdNotPrintableException` → today's flash and redirect.
- **PDFs.**
  - **Service record.** `ServiceRecordPrintout` and `ServiceRecordPdfInterface` (`render`, `html`) go in `Staff/Domain/Service/ServiceRecord/`, with the file names on the printout. The renderer is in `Staff/Infrastructure/ServiceRecord/`.
  - **Staff ID.** `StaffIdCard`, `StaffIdSize`, `StaffIdFileName`, `StaffIdImagesInterface` (`photo`, `logo`, `signature`, `seal`) and `StaffIdPdfInterface` (`render`, `html`) go in `Staff/Domain/Service/StaffId/`. `StaffIdImages` and `StaffIdPdf` go in `Staff/Infrastructure/StaffId/`.
  - **Templates** go under `@Staff/service_record/` and `@Staff/staff_id/`.
- **Signatory hook:** `Staff/Infrastructure/LessonPlans/LessonPlanSignatoryPositions`. Staff and LessonPlans are both level 6. Staff only implements LessonPlans' hook, which was decided in Phase 6.
- **No locks** and **no new log lines**, as today.

---

### Task 1: Domain, repositories, PDFs, images, signatory hook

**Files:**
- **Move:**
  - `src/Entity/{ServiceRecord,ServiceRecordEntry,StaffIdDetails}.php` → `src/Staff/Domain/Model/`, with column types as strings and no validator attributes.
  - `src/Repository/{ServiceRecord,ServiceRecordEntry,StaffIdDetails}Repository.php` → `src/Staff/Infrastructure/Repository/Doctrine*Repository.php`, each with its interface.
  - `src/ServiceRecord/ServiceRecordPdf.php` → `Staff/Infrastructure/ServiceRecord/ServiceRecordPdf` (render and html of a printout).
  - `src/StaffId/{StaffIdCard,StaffIdSize}.php` → `Staff/Domain/Service/StaffId/`.
  - `src/StaffId/StaffIdPdf.php` → `Staff/Infrastructure/StaffId/` (file names to `StaffIdFileName`).
  - The image inlining from `StaffIdCardBuilder` → `Staff/Infrastructure/StaffId/StaffIdImages`.
  - `src/ServiceRecord/LessonPlanSignatoryPositions.php` → `Staff/Infrastructure/LessonPlans/`.
  - `templates/{service_record,staff_id}/` → `src/Staff/Presentation/templates/`.
- **Modify:**
  - `Schools/Application/Api/{SchoolDirectoryInterface,SchoolDirectory}.php` (`assignmentOf`, `divisionOf`).
  - `config/deptrac.yaml` (Staff_Infrastructure gets LessonPlans_Domain; Staff_Application gets only what it uses).
  - `phpunit.dist.xml`.
  - Users of the moved names, rewritten by script: `SchoolFixturesTrait` and `LessonPlanViewControllerTest`.
- **Move tests:**
  - `tests/ServiceRecord/ServiceRecordPdfTest.php` → `tests/Staff/Integration/Infrastructure/`.
  - `tests/StaffId/StaffIdPdfTest.php` → `tests/Staff/Integration/Infrastructure/`.
  - `tests/ServiceRecord/LessonPlanSignatoryPositionsTest.php` → `tests/Staff/Integration/Infrastructure/`.
  - The repository cases of `ServiceRecordEntryTest` (`findCurrent`, `findForEmployee` order) → `tests/Staff/Integration/Infrastructure/ServiceRecordRepositoriesTest.php`.
- **Test (new):**
  - the repositories' `findFor`, `findById`, `add` and `remove`;
  - `StaffIdImages` (a bad or missing file gives null; a photo gives JPEG, a logo PNG);
  - the Schools API's `assignmentOf` and `divisionOf` in `SchoolDirectoryTest`.

**Produces:**
- `ServiceRecordPrintout(school, sheet, record, rows, certifiedName, certifiedDesignation, printedOn)`, with `fileName()` and `asciiFileName()`.
- `ServiceRecordPdfInterface::{render, html}(ServiceRecordPrintout): string`.
- `StaffIdPdfInterface::{render, html}(StaffIdCard, StaffIdSize): string`.
- `StaffIdFileName::{forCard, asciiForCard}(StaffIdCard, StaffIdSize)`.
- `StaffIdImagesInterface::{photo, logo, signature}(?string $path): ?string` and `seal(): ?string`.

- [ ] **Step 1:** Save the HEAD route dump and a baseline copy.
- [ ] **Step 2:** Move and write the tests.
- [ ] **Step 3:** Implement.
- [ ] **Step 4: Setup checks:** `cache:clear`, `lint:container`, `doctrine:schema:validate`, `doctrine:migrations:diff` empty.

### Task 2: Use cases, queries and builders

**Files:**
- **Create in `src/Staff/Application/`:**
  - The commands, queries and handlers named in the rulings.
  - `Service/ServiceRecordKeeper` (`draft`, `save` steps without the flush), moved from `src/ServiceRecord/`.
  - `Service/ServiceRecordPrinter` (`printout(User, School)`, and `missing(?PersonalDataSheet)` with today's labels).
  - `Service/StaffIdCardBuilder` (`build`, `missing`, `canPrint`), moved from `src/StaffId/`, reading through the repositories, `SchoolDirectoryInterface` and `StaffIdImagesInterface`.
  - `StaffScope::{schoolEmployee, serviceRow, idHolder}`.
  - `Domain/Exception/{StaffAccessDeniedException, StaffIdNotPrintableException}`.
- **Move tests:**
  - `tests/StaffId/StaffIdCardBuilderTest.php` → `tests/Staff/Integration/Application/`.
- **Test (new):**
  - `ServiceRecordHandlersTest`:
    - the BP number is cut to 30 and blank becomes null;
    - adding drafts and saves; closing the open row; editing;
    - deleting a row;
    - the 404s for the person, the row and a missing school year;
    - each query's shape.
  - `StaffIdHandlersTest`:
    - details saved, blank to null;
    - the 403 for an unassigned person or a student;
    - the not-printable refusal;
    - the PDF file name;
    - the typed call name shows on the card.
  - The log cases go in `tests/Staff/Unit/Application/PdsCommandLogTest.php`, renamed `StaffCommandLogTest`: no personal data in the new commands' log arrays.

- [ ] **Step 1:** Move and write the tests.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `lint:container`.

### Task 3: Service record and My ID screens; legacy removed

**Files:**
- **Move:**
  - `src/Controller/{ServiceRecordController,MyIdController}.php` → `src/Staff/Presentation/Controller/`, extending `BusController`, with the same routes and attributes.
  - `src/Security/Voter/ServiceRecordVoter.php` → `Staff/Presentation/Security/`.
  - `src/Validator/ValidServiceRow*.php` → `Staff/Presentation/Validation/`.
  - `src/Form/{ServiceRecordEntryFormType,StaffIdDetailsFormType}.php` → `Staff/Presentation/Form/`, bound to the commands. Same class names, so the form names stay.
  - The row form's title reads the command's `entryId`.
- **Delete:** `src/ServiceRecord`, `src/StaffId`, `src/Validator`, `src/Security/Voter` (if empty), `templates/{service_record,staff_id}`, and the emptied `tests/{ServiceRecord,StaffId}`. Grep config for each before deleting.
- **Move tests:**
  - `tests/Controller/{ServiceRecordControllerTest,MyIdControllerTest}.php` → `tests/Staff/Functional/`.
  - The validation cases of `ServiceRecordEntryTest` → `tests/Staff/Integration/Presentation/ServiceRowValidationTest.php`.
- **Test (new):**
  - a refused add keeps the typed values and the "Add row" title, and a refused edit keeps "Edit row";
  - a refused My ID save shows the typed big name;
  - another school's principal with a bad CSRF token gets the same status on delete and BP as on HEAD (checked against HEAD before the move);
  - an unknown school id is 404.

- [ ] **Step 1:** Move and write the tests.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `cache:clear`, `lint:container`, `lint:twig`, and the route table identical to HEAD.

### Task 4: Baselines, docs, comments, deprecations

- [ ] **Step 1: Static checks.**
  - phpcbf, then phpcs clean, with LF line endings on touched files.
  - PHPStan on the module (cache cleared), then on everything. Prune the baseline and confirm it only shrank.
  - `make test deptrac`: 0 violations and 0 uncovered.
- [ ] **Step 2: Docs.** In `CLAUDE.md`:
  - the Staff paragraph: service record and staff ID homes, voter, row constraint, PDFs, the signatory hook, and "Staff has no public API";
  - the LessonPlans sentence on `LessonPlanSignatoryPositions`;
  - the Schools API list (`assignmentOf`, `divisionOf`).
- [ ] **Step 3: Comment sweep and deprecation check** over everything touched.

### Task 5: Verification

- [ ] **Step 1: Scoped run.**
  - `tests/Staff`.
  - Then the users in Review Focus 5, one at a time.
  - Then `SchoolDirectoryTest` and `DemoFixturesTest`.
  - Fix, then re-run only the failures.
- [ ] **Step 2: Coverage** of `src/Staff`: nothing uncovered.
- [ ] **Step 3: Guard breaks.**
  - Drop the row constraint from the form, and watch the overlap case fail.
  - Drop the typed call name, and watch its case fail.
- [ ] **Step 4: Full runs on the final code, one at a time:**
  - `make test all`, then `make test-feature`, then `make regression-test`.
  - Schema valid, diff empty, routes identical.
- [ ] **Step 5: Fresh whole-change review** (most capable model, read-only).
  - Fix Critical and Important findings, and anything a user would see, with a failing test first.
  - Then the full runs again.
- [ ] **Step 6: Headless live check**, HEAD against the branch on the same dev data. Then restore the data.
  - The record page.
  - The BP number.
  - Adding rows: a refused overlap, a refused second open row, a good row, closing the open row.
  - Editing and deleting a row.
  - The PDF as principal and as admin.
  - The admin's 403.
  - My ID: a refused and a good save, the "can't print yet" flash, and both PDFs.
  - The principal's own record from the menu.
  - The lesson plan PDF's signatory line.
- [ ] **Step 7: Report** and propose the commit (code and this plan). Commit only after approval:

```
Move the service record and staff ID into the Staff module

Move the service record pages, rows, BP number and PDF, and the
staff ID page, details and PDFs into the Staff module, with every
change through the bus. The row rules now sit on the row command and
its form. The lesson plan signatory's position now comes from Staff.
Pages, messages, URLs and the PDFs are unchanged.
```
