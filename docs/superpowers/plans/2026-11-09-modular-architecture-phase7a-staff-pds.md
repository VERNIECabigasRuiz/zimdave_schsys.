# Modular architecture, Phase 7a (Staff: Personal Data Sheet) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create the `Staff` module with the Personal Data Sheet (CSC Form 212): the sheet and its list rows, the `/profile` tabs, the PDS PDF and the school's teacher PDS download. Every save goes through the Core bus, and nothing a user would notice changes.

**Architecture:** Same patterns as Phases 4–6.
- **Validation off the entities.** The entities lose `repositoryClass` and their validator attributes. Each tab gets an Application command (`SavePersonalInfoCommand`, `SaveFamilyBackgroundCommand`, …) that carries that tab's properties under the same names, with the same constraints, groups and callbacks. The tab's form binds to the command instead of the entity, and its handler applies it with `applyTo()`.
- **PDF split.**
  - `PdsListGrids` (pure layout arithmetic over the entities) becomes a Domain service.
  - The Dompdf/Twig renderer moves to Infrastructure behind a Domain `PdsPdfInterface`.
- **Later phase.** The service record and the staff ID stay legacy until Phase 7b; they read the sheet through the new repository interface.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Twig, Dompdf, PHPUnit 13, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row `Staff`, sections 5, 6, 8, 11).
- Owner decisions (2026-11-09): Staff is split in two plans. 7a covers the PDS; 7b covers the service record and staff ID, including the lesson-plan signatory hook.
- This ticket only migrates: regressions the move causes are fixed, existing flaws are left.
- The plan is committed with the phase.

**Branch:** `feature/modular-architecture` (Phase 6 committed as `c6ba065`, notes as `2b2b867`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 0. Planning | 2026-11-09 03:30:16 | 03:37:21 | 7m 5s |
| 1. PDS domain, repository, list grids and PDF | 2026-11-09 03:38:02 | 03:41:16 | 3m 14s |
| 2. Tab commands, use cases and queries | 03:41:16 | 03:47:05 | 5m 49s |
| 3. Profile and teacher PDS screens; legacy removed | 03:47:05 | 03:51:31 | 4m 26s |
| 4. Baselines, docs, comments, deprecations | 03:51:31 | 04:13:23 | 21m 52s |
| 5. Verification | 04:13:23 | 05:28:59 | 1h 15m 36s |

## Global Constraints

**Structure only.** These stay exactly as today:
- URLs, route names, HTTP methods and route requirements.
- Form names, field names and nesting, including the list tabs' `<tab>_section_form[<field>][<n>][…]` and the children and reference rows.
- Validation messages, the number of errors per field, and which tab's rules apply to which save (the validation groups).
- Flash messages, redirects, status codes, and the "not part of this school" messages.
- What each tab page shows after a refused save, including the training total. The sorting and renumbering of rows. The permanent-address copy, and the clearing of answer details on "No".
- The account's full name taken from the sheet on save. The "Updated own profile" and "Downloaded PDS of <username>" log lines.
- The PDS PDF's content and file name.
- Table and column names. `doctrine:migrations:diff` stays empty, and the route table matches HEAD.

**Layers.**
- Staff may use Core, Access (Domain, Api), Schools (Domain, Api) and Learners (Domain: `SexAtBirth`).
- Legacy (service record, staff ID, and the Twig `has_id`) may use Staff. There is no `Staff_Api` layer yet.
- In deptrac, the layers sit with the other modules and allow only the dependencies they use.

**Bus rules.**
- Controllers extend `BusController`.
- Every moved class keeps its class-level attributes: the profile's `is_granted('ROLE_PRINCIPAL') or is_granted('ROLE_TEACHER')`, and the teacher download's `SchoolDirectoryInterface::MANAGE` (same `SCHOOL_MANAGE` value as `SchoolVoter::MANAGE`).
- Command log arrays carry ids, the tab and row counts, never personal data.

**New code.**
- `declare(strict_types=1)`.
- PHPStan level 9 with no baseline entries.
- phpcs PSR-12, lines up to 120 characters.
- 100% coverage of `src/Staff`, added to `<source>` in `phpunit.dist.xml`.
- Domain entities write column types as strings and carry no validator attributes.

**Tests.**
- Moved tests keep their assertions; only the way they reach a value may change. Validation cases validate the tab's command built from the sheet, with the same property paths.
- New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first), with every setup line under `// Initialize` and `// Execute` running the code under test.
- Use the existing helpers. Check `SchoolFixturesTrait` before naming a helper.
- Kernel tests go in Integration or Functional.
- After moving a test, check its class name, `__DIR__` paths and suite, and grep config for any folder removed.

**Moving classes and helper scripts.**
- After moving classes, grep their short names, and grep templates and config for their full old names.
- Check for repeated and same-namespace imports.
- Check the scratchpad for helper scripts first (`move6_lesson_plans.py`, `dedupe_uses.py`, `prune_uses.py`, `set_namespace.py`, `replace_text.py`, `wrap_long.py`, `wrap2.py`, `prune_baseline.py`, `compare_routes.py`, `to_lf.py`, `uncovered.py`, `worktree_package.sh`, the live-check scripts). Write new ones with the Write tool, in LF.
- No inline heredocs, no `sed` for text with backslashes, no `git add -N`.
- Save the HEAD route dump and a baseline copy before the first code change.

**Running checks.**
- Tests are written per task and run once in Task 5, one command at a time, in Docker (`MSYS_NO_PATHCONV=1`). `TEST_TARGET` takes one class, method or path.
- Read each check's output.
- Clear `reports/phpstan/.cache` before a PHPStan run that counts.
- After fixing one checker's findings, rerun the others.
- Run deptrac through `make test deptrac`.
- Long runs go to the background and are watched directly.
- Log a task's end and the next task's start together. Compute durations with a command.

**PHPStan baseline.** It may only shrink. No entry may name a module folder.

**Live-browser check.**
- Ask first, and confirm headless or visible.
- Move the worktree `.superpowers/sdd/2026-11-07-modular-architecture-phase2d-schools/head` to `2b2b867`.
- Start servers without any removal in the same command.
- Reset the dev data before each side and restore it after.

**Comments and commit.**
- Comments short and plain, with no Claude/Anthropic mention.
- One commit at the end after approval, through the Base hook, including this plan (`git add -f`).

## Review Focus

1. **Validation parity.**
   - Every tab refuses exactly what it refuses today, with the same messages and the same number of errors per field. This includes the three callback rules ("yes" answers need details, all-or-nothing references, and the row rules), the 100-row and 30-children caps, number fields, and dates in the future.
   - A save on one tab never fails because of another tab's data.
   - Pinned by the moved `PersonalDataSheetTest`, `PdsQuestionsTest` and `PdsListsTest` (validating the commands), `ProfileControllerTest`, and new cases.
2. **Saving.**
   - Each tab writes only its own fields.
   - List rows keep their order rules, positions are renumbered, and a removed row is deleted.
   - The permanent address follows the residential one when the box is ticked, and "No" clears old details.
   - Empty references are dropped and three reference rows always show.
   - The sheet's "updated" time moves forward, the account's full name follows the sheet's display name (up to 120 characters), and the log line is written.
   - Pinned by the moved controller and entity tests, and new handler tests.
3. **The page after a refused save.** It shows what was typed. The training tab's total counts the rows on the form, as today.
4. **First visit and the PDF.**
   - Opening any profile page creates the sheet once, as today.
   - The PDF prints every section, the photo, the continuation sheets and the file name as today.
   - The school's teacher download refuses a teacher not at the school this year (404 with today's message), logs the download, and prints an empty sheet for a teacher without one.
   - Pinned by the moved PDF and controller tests and `SchoolTeacherControllerTest`.
5. **Legacy users keep working.**
   - The service record's "missing PDS details" list, the staff ID card (name, address, birthday, contacts from the sheet), the teacher's "My ID" link (`has_id`), and the profile photo pages.
   - Pinned by `ServiceRecordControllerTest`, `StaffIdCardBuilderTest`, `MyIdControllerTest`, `TeacherAreaTest` and `ProfilePhotoControllerTest`.

## Rulings in this plan

- **Module name.** `Staff`, as the spec names it: `src/Staff`, `@Staff`, `tests/Staff`. The PDS goes in now; the service record and staff ID join in 7b.
- **Tab commands.** Each tab command lives in `Staff/Application/UseCase/Command/` (`SavePersonalInfoCommand`, `SaveFamilyBackgroundCommand` with `PdsChildRow`s, `SaveOtherInfoCommand`, `SaveQuestionsCommand`, `SaveReferencesCommand` with `PdsReferenceRow`s, and `SavePdsListCommand` with a tab key and `PdsEducationRow` / `PdsEligibilityRow` / `PdsWorkExperienceRow` / `PdsTrainingRow` / `PdsVoluntaryWorkRow`).
  - Each command holds that tab's properties under the entity's property names, with the entity's constraint attributes, groups and callback methods moved over unchanged.
  - Each has `static fromSheet(PersonalDataSheet): self` and `applyTo(PersonalDataSheet): void`.
  - The forms keep their `validation_groups`. Group names stay as today's `PersonalDataSheet` constants.
- **List rows** are applied in form order: existing rows are updated by position, extra rows added and missing ones removed (orphan removal deletes them). The entity's renumbering then sorts them as today.
- **First visit.** Opening a profile page sends `OpenPersonalDataSheetCommand` (create the sheet if missing, as today's `findOrCreateFor` + flush), then asks `GetPersonalDataSheetCommand` for the page.
- **The page after a refused save** renders the saved sheet with the form's submitted values. The training tab's total is computed from the form's rows (`PdsTrainingRow` hours), matching today's in-memory sheet.
- **The account's full name** is set by the save handlers on the actor's `User`, as Schools' teacher handlers already do with Access's entity.
- **Teacher download.** The school's teacher PDS download is a use case (`DownloadTeacherPdsCommand`), because it writes a log line. It finds the teacher among `SchoolDirectoryInterface::teachersOf(school, current year)`, and returns the PDF content and file name.
- **PDF.** `PdsPdfInterface` (`render`, `html`, `fileName`) lives in `Staff/Domain/Service/Pds/` with `PdsListGrids`. The Dompdf/Twig/photo renderer is `Staff/Infrastructure/Pds/PdsPdf`.
- **Voter.** No voter is added. The profile only ever shows the actor's own sheet, and the teacher download is guarded by `SCHOOL_MANAGE` plus the school-membership check.
- **No locks**, as today.

---

### Task 1: PDS domain, repository, list grids and PDF

**Files:**
- **Move:**
  - `src/Entity/{PersonalDataSheet,PdsChild,PdsEducation,PdsEligibility,PdsReference,PdsTraining,PdsVoluntaryWork,PdsWorkExperience,Citizenship,CivilStatus,DualCitizenshipBy,EducationLevel}.php` → `src/Staff/Domain/Model/`. Column types become strings, and the validator attributes and callbacks move out in Task 2.
  - `src/Repository/PersonalDataSheetRepository.php` → `src/Staff/Infrastructure/Repository/DoctrinePersonalDataSheetRepository.php`, with `Domain/Model/Repository/PersonalDataSheetRepositoryInterface` (`findFor(User): ?PersonalDataSheet`, `add`).
  - `src/Service/PdsListGrids.php` → `src/Staff/Domain/Service/Pds/`.
  - `src/Service/PdsPdfRenderer.php` → `src/Staff/Infrastructure/Pds/PdsPdf.php`, implementing `Domain/Service/Pds/PdsPdfInterface`. It renders `@Staff/profile/pdf/...`.
- **Modify:**
  - `config/packages/doctrine.yaml`, `config/deptrac.yaml`, `config/packages/twig.yaml` (`@Staff`), `config/phpcs.xml`, `phpunit.dist.xml`.
  - Legacy users: the service record, staff ID, fixtures and `SchoolFixturesTrait`, rewritten by script to the new names and the repository interface.
- **Move tests:**
  - `tests/Service/PdsPdfRendererTest.php` → `tests/Staff/Integration/Infrastructure/PdsPdfTest.php`.
  - The entity-behaviour cases of `tests/Entity/{PersonalDataSheetTest,PdsListsTest,PdsQuestionsTest}.php` → `tests/Staff/Integration/Domain/`. Their validation cases follow in Task 2.
- **Test (new):** the repository's `findFor`/`add`.

**Produces:** `PersonalDataSheetRepositoryInterface::{findFor, add}`, `PdsPdfInterface::{render(PersonalDataSheet): string, html(PersonalDataSheet): string, fileName(PersonalDataSheet): string}`, `PdsListGrids::build()`.

- [ ] **Step 1:** Save the HEAD route dump and a baseline copy.
- [ ] **Step 2:** Move and write the tests.
- [ ] **Step 3:** Implement.
- [ ] **Step 4: Setup checks:** `cache:clear`, `lint:container`, `doctrine:schema:validate`, `doctrine:migrations:diff` empty.

### Task 2: Tab commands, use cases and queries

**Files:**
- **Create in `src/Staff/Application/`:**
  - The tab commands and row classes named in the rulings, carrying the entities' constraints, groups and callbacks, and their handlers. Each handler:
    - sends nothing until the tab is valid;
    - applies the tab, then today's follow-up (`syncPermanentAddress`, `renumberChildren`, `renumberLists`, `clearUnusedAnswerDetails`, `removeEmptyReferences`);
    - touches the sheet, sets the account's full name and logs "Updated own profile".
  - `OpenPersonalDataSheetCommand(actorId)`.
  - `GetPersonalDataSheetCommand(actorId)`: the sheet, read-only.
  - `GetPdsPdfCommand(actorId)`: the actor's own PDF, as content and file name.
  - `DownloadTeacherPdsCommand(actorId, schoolId, teacherId)`: content and file name; 404s with today's messages.
  - `Service/StaffScope.php`: the account, and the school's teacher this year.
- **Move tests:** the validation cases of the three entity tests → `tests/Staff/Integration/Application/`, validating the commands built with `fromSheet()`.
- **Test (new):**
  - `PdsHandlersTest`:
    - each tab saves only its own fields;
    - each runs its follow-up, sets the full name (cut to 120) and logs;
    - list rows are updated, added and removed;
    - opening creates the sheet once;
    - the teacher download's 404s and log line;
    - each query's shape.
  - `PdsCommandLogTest`: no personal data in the log arrays.

- [ ] **Step 1:** Move and write the tests.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `lint:container`.

### Task 3: Profile and teacher PDS screens; legacy removed

**Files:**
- **Move:**
  - `src/Controller/{ProfileController,SchoolTeacherPdsController}.php` → `src/Staff/Presentation/Controller/`, extending `BusController`, with the same routes and class-level attributes.
  - The PDS forms (`PersonalInfo`, `FamilyBackground`, `OtherInfo`, `Questions`, `References`, `PdsList`, `PdsChild`, `PdsEducation`, `PdsEligibility`, `PdsReference`, `PdsTraining`, `PdsVoluntaryWork`, `PdsWorkExperience` form types and `PdsListSections`) → `src/Staff/Presentation/Form/`. They bind to the commands and row classes, keeping their block prefixes and validation groups.
  - `templates/profile/*` → `src/Staff/Presentation/templates/profile/` (`@Staff/...`). The training total comes from the form when it was submitted.
- **Delete:** the emptied legacy folders and files, after grepping config for them. That includes the empty `templates/{lesson_plans,nutrition}` left by earlier phases.
- **Move tests:** `tests/Controller/ProfileControllerTest.php` → `tests/Staff/Functional/`.
- **Test (new):**
  - a refused list save shows the typed rows' training total;
  - a refused personal-info save keeps what was typed;
  - a former principal or teacher (now a student) gets 403 on every profile route.

- [ ] **Step 1:** Move and write the tests.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `cache:clear`, `lint:container`, `lint:twig`, and the route table identical to HEAD.

### Task 4: Baselines, docs, comments, deprecations

- [ ] **Step 1: Static checks.**
  - phpcbf, then phpcs clean.
  - LF line endings on touched files.
  - PHPStan on the module (cache cleared), then on everything; prune the baseline and confirm it only shrank.
  - `make test deptrac`: 0 violations and 0 uncovered.
- [ ] **Step 2: Docs.**
  - In `CLAUDE.md`: the Staff module so far (PDS entities, tab commands with the validation, PDF placement, the first-visit use case, no API yet).
  - Remove "The teacher PDS download stays legacy until Staff moves".
  - Update the PDS domain term.
- [ ] **Step 3: Comment sweep and deprecation check** over everything touched.

### Task 5: Verification

- [ ] **Step 1: Scoped run.** `tests/Staff`, then the legacy users named in Review Focus 5, then `SchoolTeacherControllerTest`, `DemoFixturesTest` and `LessonPlanSignatoryPositionsTest`. Fix and re-run only the failures.
- [ ] **Step 2: Coverage** of `src/Staff`: nothing uncovered.
- [ ] **Step 3: Guard breaks.**
  - Drop one constraint from a tab command and watch its validation case fail.
  - Drop the training-total fix and watch its case fail.
- [ ] **Step 4: Full runs on the final code, one at a time:** `make test all`, `make test-feature`, `make regression-test`. Schema valid, diff empty, routes identical.
- [ ] **Step 5: Fresh whole-change review** (most capable model, read-only). Fix Critical and Important findings, and anything a user would see, with a failing test first; then the full runs again.
- [ ] **Step 6: Ask the owner before the live-browser check, and whether headless or visible.** If headless, compare HEAD and the branch on the same dev data:
  - every profile tab: a refused save and a good save on each;
  - adding and removing list rows;
  - the PDS PDF;
  - the principal's teacher PDS download.

  Then restore the data.
- [ ] **Step 7: Report** and propose the commit (code and this plan). Commit only after approval:

```
Move the personal data sheet into the Staff module

Create the Staff module with the personal data sheet and its lists,
the profile tabs, the PDS PDF and the school's teacher PDS download,
with every save through the bus. Each tab's rules now sit on its own
command instead of the entities. Pages, messages, URLs and the PDF
are unchanged.
```
