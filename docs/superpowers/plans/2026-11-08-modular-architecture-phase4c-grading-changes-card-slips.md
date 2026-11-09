# Modular architecture, Phase 4c (Grade change requests and card slips) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the `Grading` module. Grade change requests and card slips move in, with their services, screens, forms and templates, and every change goes through the Core bus. The last legacy grading code goes away: `GradeChangeManager`, `GradeLocks`, the legacy `GradingException`, `src/CardSlip`, `GradeVoter` and `CardSlipVoter`. Nothing a user would notice changes.

**Architecture:** Same patterns as 4b.

`GradeChangeManager` becomes an Application service without locks or flushes. Its handlers take today's keys before reading anything:
- sending a request takes `grades-<section>-<subject>` plus a shared hold on `grade-lock-<school>`;
- approving takes `grades-<section>-<subject>` plus `grade-lock-<school>` exclusively;
- declining and withdrawing take `grade-lock-<school>` exclusively.

A change on a request reads it fresh (`findFresh()`), replacing today's `refresh()`. Approving also reads the grades fresh. Actions whose URL doesn't carry the ids to lock first ask a query for them, as 4a's transfer actions do.

Card slips:
- the value objects and a PDF interface go to `Domain/Service/CardSlip/`;
- the builder, suggester, comment catalog, term attendance and comment recorder go to Application;
- the Dompdf/Twig renderer goes to `Infrastructure/CardSlip/`.

Card slips read attendance only through the Attendance API (`AttendanceDirectoryInterface::tallies()`), a peer reached through its public API. `GradePolicy` takes over the change-request and card-slip rules, and both voters are deleted.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Symfony Lock (flock), Twig, Dompdf (Core `PdfRendererInterface`), PHPUnit 13, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row `Grading`, sections 5, 6, 8, 11). Owner decisions (2026-11-08):
- Grading is split into 4b (committed as `596c3ca`) and 4c (this plan).
- This ticket only migrates: regressions the move causes are fixed, existing flaws are left.
- The plan is committed with the phase.

**Branch:** `feature/modular-architecture` (notes committed as `2647738`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 0. Planning | 2026-11-08 19:54:32 | 19:57:11 | 2m 39s |
| 1. Change request services, use cases and queries | 2026-11-08 19:58:41 | 20:03:18 | 4m 37s |
| 2. Card slip domain, services, PDF and use cases | 2026-11-08 20:03:18 | 20:07:06 | 3m 48s |
| 3. Change request and card slip screens; legacy removed | 2026-11-08 20:07:06 | 20:12:02 | 4m 56s |
| 4. Baselines, docs, comments, deprecations | 2026-11-08 20:12:02 | 20:24:51 | 12m 49s |
| 5. Verification | 2026-11-08 20:24:51 | 2026-11-09 00:07:04 | 3h 42m 13s |

## Global Constraints

- **Structure only.** These stay as today:
  - URLs, route names, HTTP methods and route requirements;
  - CSRF token ids (`grade-change-approve-<request>`, `grade-change-withdraw-<request>`, `card-slip-reset-<enrollment>`, the decline, change and comment forms);
  - form names (`decline_<request>`, `grade_change_form[...]`, `card_slip_comments_form[...]`);
  - flash, form-error and refusal messages, activity-log lines, status codes (the decline page's 422 included), redirects and access rules;
  - the card slip PDFs' content, file names and `Content-Disposition` fallback.

  Table and column names stay, `doctrine:migrations:diff` stays empty, and the route table matches HEAD.
- **Layer rules.**
  - Grading may use Core, Access (Domain, Api), Schools (Domain, Api), Classes (Domain, Api), Learners (Domain, Api) and Attendance's Api (new rule for `Grading_Application`).
  - Legacy may use Grading. Grading never uses another module's repositories or legacy code.
  - Once `src/Grading` holds no legacy file, config names `src/Grading` and `tests/Grading` whole again: deptrac's Legacy collector, `config/phpcs.xml` and `phpunit.dist.xml`.
- **Bus and locks.**
  - Controllers extend the module's `AbstractGradingController`.
  - Handlers refuse by throwing a `DomainException`, take their locks before any read, and read what a page query may have loaded fresh.
  - Every moved or split class keeps its class-level attributes: `#[IsGranted(User::ROLE_TEACHER)]` with `#[Route('/teacher/class/card-slips', requirements: [...])]` or `#[Route('/teacher/grades')]`; `#[Route('/schools/{id}/grade-changes', requirements: [...])]` or `#[Route('/schools/{id}', requirements: [...])]` with `#[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]`.
- **New code:** `declare(strict_types=1)`, PHPStan level 9 with no baseline entries, phpcs PSR-12 (lines ≤ 120), 100% coverage of `src/Grading`. Domain entities write column types as strings.
- **Tests.**
  - Moved tests keep their assertions.
  - New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first), with every setup line under `// Initialize` and `// Execute` running the code under test. They use the existing helpers (`SavesGrades`, `SavingGradeRecorder`, the fixture trait).
  - Tests that boot the kernel never go in Unit.
  - Compare ids, not entities, in assertions that may fail.
  - Reload an entity after a request before changing it.
  - A test meant to guard a fix is broken once to watch it fail.
  - After moving a test, check its class name, `__DIR__` paths and suite.
- **Helper scripts.**
  - Check the scratchpad first (the 4b scripts: `move4b.py` and its derivers, `set_namespace.py`, `replace_text.py`, `insert_after.py`, `cut_tests.py`, `remove_private.py`, `prune_uses.py`, `int_keys.py`, `wrap_long.py`, `wrap2.py`, `to_lf.py`, `prune_baseline.py`, `baseline_check.py`, `baseline_renamed.py`, `compare_routes.py`, `uncovered.py`, the live-check scripts).
  - Write new ones with the Write tool, writing LF. Never type `<<` in a shell command. No `sed` for text with backslashes; `//` arguments go through a file; no `git add -N`.
  - After moving classes to a new namespace, grep their short names in every file that used them without an import.
  - Save the HEAD route dump and a baseline copy before the first code change.
- **Running checks.**
  - Tests are written per task and run once in Task 5, one command at a time, in Docker (`MSYS_NO_PATHCONV=1`).
  - Run the module's tests with coverage before the full `make test-feature`.
  - Read each check's output before recording it as passed.
  - Clear `reports/phpstan/.cache` before a PHPStan run whose result counts.
  - Long runs go to the background and are watched directly. Leftover servers, monitors and shells are stopped.
- **PHPStan baseline:** prune entries for deleted files and regenerate on a clear cache. The total must not grow, no entry may name a module folder or a file not on `develop`, and every new entry must be an old one renamed (`baseline_renamed.py`).
- **Live-browser check:** ask first and confirm headless or visible. Serve both sides through the `php -S` router, reset the dev data before each side and restore it after.
- **Comments and commit:** comments short and plain, with no Claude/Anthropic mention. One commit at the end after approval, through the Base hook, including this plan (`git add -f`).

## Review Focus

1. **Two changes at once.**
   - Sending a request holds the class lock and a shared school hold. Approving holds the class lock and the school lock exclusively. Declining and withdrawing hold the school lock.
   - Each refuses with "Another change is being saved. Please try again." while another holds it.
   - A request decided or withdrawn elsewhere after the page was read is read fresh under the lock ("This request has already been decided."). So is a grade changed after the request was sent ("Grades have changed since this request was sent. Decline it so the teacher can send a new one.").
   - Pinned by bus tests: held locks, then a page query, a change by SQL, and the use case.
2. **Who may do what.**
   - Only the class's current teacher sends a request, and only for a locked period.
   - Only the sender withdraws.
   - Only the school's principal (not an administrator) approves or declines; administrators see the list.
   - Only the current-year adviser (not an administrator) edits card slip comments.
   - The adviser, the school's principal and administrators print card slips.
   - Another school's request, section or learner is a 404. A teacher without an advisory gets 403 "You do not advise a section this school year.".
   - Pinned by `GradePolicyTest` (from both voter tests) and the moved controller tests.
3. **Request and decision rules.**
   - Every refusal message of today's manager is kept: not locked, unlocked class, closed period, pending already, the reason and note lengths, out-of-range grades, nothing changed.
   - Approving sets the requester as the grades' author.
   - Dropped learners' values are ignored.
   - A withdrawal sends the teacher back to the sheet only while they may still view the class.
   - The decline page re-renders with the note's error and a 422.
   - Pinned by the moved `GradeChangeManagerTest` and controller tests.
4. **Card slip content.**
   - Grades, averages and descriptors, components, remarks, attendance counts, the suggested comments and the saved ones (catalog and contradiction filters), and the draft mark until locked.
   - The default term is the latest locked, else the grid's default.
   - The PDF has two slips per page and a logo as a PNG data URI.
   - Saving only what differs from the suggestions; resetting a learner's comments.
   - Pinned by the moved card slip tests.
5. **Legacy keeps working.** The principal dashboard's "changes waiting" card and failing list, the teacher's grade sheet with its change requests and withdraw button, and the demo fixtures. Pinned by the legacy suite and the moved tests.

## Rulings in this plan

- **Change request use cases.**
  - `RequestGradeChangeCommand(actorId, loadId, periodId, schoolId, sectionId, subjectId, values, reason)`.
  - `ApproveGradeChangeCommand(actorId, schoolId, requestId, sectionId, subjectId)`.
  - `DeclineGradeChangeCommand(actorId, schoolId, requestId, note)`.
  - `WithdrawGradeChangeCommand(actorId, requestId, schoolId)`.
  - Approve's and withdraw's ids come from a `GetGradeChangeCommand` query: the 404 and 403 checks, the request, its school, section and subject ids, and whether it is pending.
  - The withdraw result carries the load and period to redirect to, or none.
- **Fresh reads.** `GradeChangeRequestRepositoryInterface` gains `findFresh(int $id)`, replacing `EntityManager::refresh()`. `add()` joins it, and `CardSlipCommentRepositoryInterface` gains `add()`/`remove()`.
- **Card slip comments** keep today's behaviour of saving without a lock (no key exists today). The use cases are `SaveCardSlipCommentsCommand(actorId, periodId, entries, suggested)` and `ResetCardSlipCommentCommand(actorId, periodId, enrollmentId)`; the reset returns the learner's name for the flash.
- **Card slip domain.**
  - `CardSlip`, `CardSlipLine`, a new `CardSlipAttendance` (the same four fields as `AttendanceTally`, so templates read it unchanged), `CardSlipFileName` (today's two file-name builders) and `CardSlipPdfInterface` (`render(list<CardSlip>): string`, `html(list<CardSlip>): string`) go in `Domain/Service/CardSlip/`.
  - The Domain may not name the Attendance API's type, which is why `CardSlipAttendance` exists.
  - `TermAttendance` reads the class list through the Learners API.
- **Grading templates.** All of them move to `@Grading` (`grades/*`, `card_slip/*`); the legacy principal dashboard includes `@Grading/grades/_changes_waiting_card.html.twig`. The `_change_requests` partial reads a `withdrawable` list of request ids from the sheet query instead of `is_granted('GRADE_CHANGE_WITHDRAW', ...)`.
- **Policy.** `GradePolicy` gains `CHANGE_WITHDRAW` and `CHANGE_DECIDE` (on a `GradeChangeRequest`) and `CARD_SLIP_EDIT` and `CARD_SLIP_PRINT` (on a `Section`), with today's voter rules. `GradeVoterTest` and `CardSlipVoterTest` fold into `GradePolicyTest`.
- **Controllers.** `TeacherGradeChangeController`, `SchoolCardSlipController`, `SchoolGradeChangeController` and `TeacherCardSlipController` move into `Grading/Presentation/Controller/` as they are split today; class attributes are copied.

---

### Task 1: Change request services, use cases and queries

**Files:**
- Move: `src/Grading/GradeChangeManager.php` → `src/Grading/Application/Service/GradeChangeManager.php`. It loses its locks and flush and refuses with the Domain `GradingException`. `refuseUnlessPending` checks a request the handler read through `findFresh()`, and approving reads grades through `findFreshForEnrollments()`.
- Delete: `src/Grading/GradeLocks.php`, `src/Grading/GradingException.php`.
- Modify:
  - `GradeChangeRequestRepositoryInterface` and its Doctrine class (`findFresh`, `add`);
  - `GradePolicy` (change-request attributes);
  - `GetGradeSheetHandler` (`withdrawable` ids).
- Create:
  - `UseCase/Command/` and `UseCase/Handler/` for the four change-request use cases;
  - `Query/Command/` and `Query/Handler/` for:
    - `GetGradeChangeFormCommand`: the teacher's change form, with today's redirect refusals and the ids to lock;
    - `GetGradeChangeCommand`: one request of the school, or of the sender;
    - `GetGradeChangesCommand`: the school's pending or decided list, labels, decidable ids and pending count.
- Move tests: `tests/Grading/GradeChangeManagerTest.php` → `tests/Grading/Integration/Application/`, through a saving helper. Its fresh-read case goes through `findFresh()`.
- Test (new): `GradeChangeHandlersTest`:
  - each use case through the bus;
  - held class and school locks, both ways;
  - a page query, then a change by SQL (decided elsewhere; a grade changed), then the use case;
  - 403 and 404 refusals;
  - each query's shape.

- [ ] **Step 1:** Save the HEAD route dump and a baseline copy (scratchpad).
- [ ] **Step 2:** Move and write the tests above.
- [ ] **Step 3:** Implement.
- [ ] **Step 4: Setup checks:** `cache:clear`, `lint:container`.

### Task 2: Card slip domain, services, PDF and use cases

**Files:**
- Move: `src/CardSlip/{CardSlip,CardSlipLine}.php` → `src/Grading/Domain/Service/CardSlip/`.
- Create in the same folder: `CardSlipAttendance`, `CardSlipFileName`, `CardSlipPdfInterface`.
- Move: `src/CardSlip/{CardSlipBuilder,CardSlipSuggester,CardSlipComments,TermFacts,TermAttendance,CardSlipCommentRecorder}.php` → `src/Grading/Application/Service/`. The recorder no longer flushes and uses repository `add`/`remove`.
- Move: `src/CardSlip/CardSlipPdf.php` → `src/Grading/Infrastructure/CardSlip/CardSlipPdf.php`, implementing the interface and rendering `@Grading/card_slip/pdf.html.twig`.
- Modify: `CardSlipCommentRepositoryInterface` and its class (`add`, `remove`); `GradePolicy` (card-slip attributes); `config/deptrac.yaml` (`Grading_Application` may use `Attendance_Api`).
- Create:
  - `SaveCardSlipCommentsCommand` and `ResetCardSlipCommentCommand` with their handlers;
  - queries:
    - `GetCardSlipsCommand`: the adviser's page (slips, labels, locked flag, groups, default term);
    - `GetCardSlipPdfCommand(actorId, ?schoolId, ?sectionId, periodId, ?enrollmentId)`: the adviser's or the school's PDF, with its file name, or none when there are no learners.
- Move tests:
  - `tests/CardSlip/{CardSlipBuilderTest,CardSlipPdfTest,TermAttendanceTest}.php` → `tests/Grading/Integration/{Application,Infrastructure}/`;
  - `tests/CardSlip/{CardSlipCommentsTest,CardSlipSuggesterTest}.php` → `tests/Grading/Unit/Application/`.
- Test (new): `CardSlipHandlersTest`: save and reset through the bus, refusals (no advisory, administrator, another school), each query's shape.

- [ ] **Step 1:** Move and write the tests above.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `lint:container`.

### Task 3: Change request and card slip screens; legacy removed

**Files:**
- Move to `src/Grading/Presentation/Controller/`, on the bus with the same routes and class attributes: `src/Controller/{TeacherGradeChangeController,SchoolGradeChangeController,TeacherCardSlipController,SchoolCardSlipController}.php`.
- Move forms: `src/Form/{GradeChange,GradeChangeFormType,GradeChangeDeclineFormType,CardSlipCommentEntryFormType,CardSlipCommentsData,CardSlipCommentsFormType}.php` → `src/Grading/Presentation/Form/`, keeping the same block prefixes.
- Move templates: `templates/grades/*`, `templates/card_slip/*` → `src/Grading/Presentation/templates/`. Update `@Grading` paths (the dashboard include too) and the `withdrawable` check.
- Delete: `src/Security/Voter/{GradeVoter,CardSlipVoter}.php` and the emptied `src/CardSlip/`.
- Config: `src/Grading` and `tests/Grading` whole in deptrac, phpcs and phpunit; the legacy suite drops `tests/CardSlip` and the `tests/Grading` file.
- Move tests: `tests/Controller/{SchoolGradeChangeControllerTest,TeacherCardSlipControllerTest,TeacherGradeChangeControllerTest,SchoolCardSlipTest}.php` → `tests/Grading/Functional/`. `tests/Security/{GradeVoterTest,CardSlipVoterTest}.php` fold into `GradePolicyTest`.
- Test (new):
  - a request sent while the class is being changed answers with the busy message;
  - a former teacher gets 403 on every card-slip and change route.

- [ ] **Step 1:** Move and write the tests.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `lint:container`, `lint:twig`, route table identical to HEAD.

### Task 4: Baselines, docs, comments, deprecations

- [ ] **Step 1: Static checks.**
  - phpcbf, then phpcs clean.
  - LF line endings on every touched file.
  - PHPStan on the module folders (clear cache), then regenerate the baseline and check it with `baseline_check.py` and `baseline_renamed.py`.
  - deptrac 0; ESLint and Stylelint clean.
- [ ] **Step 2: Docs.** Update `CLAUDE.md`: Grading complete (change requests, card slips, their locks); no legacy grading code left; the Attendance API used by card slips.
- [ ] **Step 3: Comment sweep and deprecation check** (`debug:container --deprecations`) over everything touched.

### Task 5: Verification

- [ ] **Step 1: Scoped run** of `tests/Grading`, the dashboard, fixture and attendance API tests. Fix, then re-run only the failures.
- [ ] **Step 2: Coverage.** Run the module's tests with coverage; nothing in `src/Grading` is left uncovered.
- [ ] **Step 3: Full runs on the final code, one at a time:**
  - `make test all NO_PARALLEL=1` (fresh PHPStan);
  - `make test-feature`;
  - `make regression-test`;
  - schema valid and diff empty; route table identical to HEAD.
- [ ] **Step 4: Fresh whole-change review** (most capable model, read-only). Fix Critical and Important findings with a failing test first, then repeat the full runs.
- [ ] **Step 5: Ask the owner before the live-browser check, and whether headless or visible.** If headless, compare HEAD and the branch on the same dev data, reset before each side, then restore the dev data:
  - a change request sent, approved, declined (with a short note, then a valid one) and withdrawn;
  - the school's pending and decided lists;
  - the card slip page (saving comments, resetting one learner);
  - the adviser's and the school's PDFs, all slips and one;
  - the principal dashboard.
- [ ] **Step 6: Report** and propose the commit (code and this plan); commit only after approval:

```
Move change requests and card slips into the Grading module

Grade change requests and card slips move into the Grading module, with
their services, pages, forms and templates, every change through the
bus. Card slips read attendance through the Attendance API, and the
grade and card slip voters give way to the grading policy. No grading
code is left outside the module.
```
