# Modular architecture, Phase 3d (Learners: transfers, SF1 and learner IDs) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the `Learners` module: move transfer requests (the Incoming / Outgoing page, requesting, approving, declining, cancelling), the SF1 export and the learner ID cards into it, every action through the Core bus, and delete the legacy classes they replace — with nothing a user would notice changing.

**Architecture:** Same patterns as 3c. `TransferManager` becomes a Learners Application service `TransferRequests` that checks and applies a change without locking or flushing; its use case handlers take `learner-<id>` (and, to approve, `learners-school-<receiving id>`) first, then read fresh, so `LearnerLock` and `EnrollmentGuard` go away. Transfer actions follow 3c's enroll pattern: a query returns the ids to lock, then the use case runs. SF1 and ID cards split by layer: value objects and interfaces in Domain, builders in Application, PhpSpreadsheet/Dompdf/QR/GD code in Infrastructure, downloads as queries returning the file name and content.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Twig, PhpSpreadsheet, Dompdf, chillerlan/php-qrcode, PHPUnit 13, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row `Learners`, sections 5, 6, 8, 11). Owner decisions (2026-11-08): Learners in two plans (3c committed as `5a59b4b`; 3d here); this ticket only migrates (regressions the move causes are fixed, existing flaws are left).

**Branch:** `feature/modular-architecture` (3c committed as `5a59b4b`, notes as `dd80cdc`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 0. Planning | 2026-11-08 11:33:24 | 11:38:06 | 4m 42s |
| 1. SF1 and ID cards | 11:49:35 | 11:55:49 | 6m 14s |
| 2. Transfer use cases and queries | 11:55:49 | 12:01:26 | 5m 37s |
| 3. Transfer and download screens | 12:01:26 | 12:04:43 | 3m 17s |
| 4. Baselines, docs, comments, deprecations | 12:04:43 | 12:14:55 | 10m 12s |
| 5. Verification | 12:14:55 | 13:57:47 | 1h 42m 52s |

## Global Constraints

- Structure only: URLs, route names, HTTP methods, route requirements, CSRF token ids (`transfer-<id>`, the transfer form's `enroll_section_form`), form field names (`enroll_section_form[section]`), flash and refusal messages (including the "Approval refused: " prefix), activity-log lines, status codes, redirects (with `tab`), access rules, download file names, content types and the `Content-Disposition` fallback stay as today. `doctrine:migrations:diff` stays empty; the route table matches HEAD.
- Learners may use Core, Access, Billing, Schools and Classes (Domain, Api); legacy may use Learners. No legacy class may be used by the module.
- Controllers extend `BusController`; handlers refuse by throwing a `DomainException`; handlers that lock take every lock before any read (learner, then receiving school). Every class keeps its class-level attributes when moved or split (`#[IsGranted(User::ROLE_TEACHER)]` on the teacher routes, `#[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]` on the school download routes).
- Times come from `SchoolClockInterface` converted to the server time zone (3c ruling), so stored timestamps, the SF1 "generated on" date and its end-of-year counts don't shift.
- New code: `declare(strict_types=1)`, PHPStan level 9 with no baseline entries, phpcs PSR-12 (lines ≤ 120), 100% coverage of `src/Learners`.
- Moved tests keep their assertions. New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first) with every setup line under `// Initialize`, the existing helpers, and `createStub` unless the test sets expectations. In functional tests, reload an entity through its repository after a request before changing it.
- Helper scripts: check the scratchpad first (`move3c.py`, `set_namespace.py`, `replace_text.py`, `wrap_long.py`, `prune_baseline.py`, `baseline_check.py`, `compare_routes.py`, `to_lf.py`, the live-check scripts); new ones with the Write tool, writing LF; no inline heredocs; no `sed` for text with backslashes; no `git add -N`. Save the HEAD route dump and a baseline copy before the first code change.
- Tests are written per task and run once in Task 5, one command at a time, in Docker (`MSYS_NO_PATHCONV=1`). Clear `reports/phpstan/.cache` before a PHPStan run whose result counts. Long runs go to the background and are watched directly; leftover servers, monitors and shells are stopped.
- PHPStan baseline: prune entries for deleted files, regenerate on a clear cache; the total must not grow; no entry may name a module folder or a file not on `develop`.
- Live-browser check: ask first and confirm headless or visible; serve both sides through the `php -S` router; check the saved HTML isn't empty; reset the dev data before each side and restore it after. Comments short and plain; no Claude/Anthropic mention. One commit at the end after approval, through the Base hook. Plan and ledger stay uncommitted.

## Review Focus

1. **Two decisions at once.** Approving, declining or cancelling a request someone else just decided answers "This request was already decided." (to page users; outsiders still get 403); a second pending request for the learner is refused; a change while another holds the learner or receiving school answers "Another change is being saved. Please try again."; approval re-checks the receiving school's limits ("Approval refused: …"). Pinned by the moved `TransferManagerTest` cases (Task 2) and `TransferHandlersTest`.
2. **Who sees and decides.** Admin and the school's principal see both tabs; on a plan without a principal the adviser sees only their section's outgoing requests (incoming is 403 with today's message); others get 403. Deciding: principal of the sending school, or its adviser without a principal; cancelling: principal of the receiving school; requesting: a principal of the receiving school, never an admin. Pinned by the moved `SchoolTransferControllerTest` and `LearnerPolicyTest`.
3. **Downloads.** Same file names (`IDs_<year>_<grade> - <SECTION>.pdf`, `ID_<lrn>.pdf`, the SF1 name), underscores for non-ASCII in the fallback, `/`, `\` and `%` replaced, layout falling back to the sheet, dropped learners left off the ID cards, 403/404 per role and page. Pinned by the moved controller and builder tests.
4. **SF1 content.** Grades 1–3 only print the mother tongue; the end-of-year counts appear only after the school year ends; the adviser's and principal's names; the "generated on" date in the server's time zone. Pinned by the moved `Sf1ReportBuilderTest` and `Sf1WriterTest`.
5. **Legacy users keep working.** The dashboards' "transfers waiting" card, attendance, grading and nutrition refusing with the same busy message, the adviser's grades page. Pinned by the legacy suite.

## Rulings in this plan

- `LearnerLock` and `EnrollmentGuard` are deleted. Attendance, grading and nutrition only use `LearnerLock::BUSY`; they switch to `LearnerEnrollment::BUSY` (same text). As in 3c, lock-first replaces the "moved meanwhile" re-read: a request or enrollment is read after the locks, so `EnrollmentGuard::MOVED` is no longer needed.
- Transfer actions make two bus calls, as 3c's enroll page does: a query (`GetTransferCommand`, or `GetEnrollPageCommand` for a request) checks the page and returns the ids to lock; the use case locks, re-reads and re-checks.
- `LearnerVoter` is deleted (its last users move here); `LearnerVoterTest` becomes `LearnerPolicyTest` calling `LearnerPolicy::can()` with the same cases. The transfer page shows Approve / Decline / Cancel from `canDecide` / `canCancel` flags in the query result, set by the policy.
- Schools' API gains `principalOf(School, SchoolYear): ?User` (SF1 and ID cards print the school head; Learners may not use the Schools repositories).
- The ID card file name moves to a Domain helper (`IdCardFileName::forSection(Section)`); `IdCardPdf::sheetPages()` stays static on the Infrastructure renderer.
- The legacy `EnrollSectionFormType` is replaced by `TransferRequestFormType` (block prefix `enroll_section_form`, one `section` field, "Choose a section."), used by the enroll page's transfer form and the request action.

---

### Task 1: SF1 and ID cards

**Files:**
- Move: `src/Sf1/{Sf1Report,Sf1Row}.php` → `src/Learners/Domain/Service/Sf1/`; `src/LearnerId/{IdCard,IdLayout}.php` → `src/Learners/Domain/Service/LearnerId/`; `src/Sf1/Sf1ReportBuilder.php`, `src/LearnerId/IdCardBuilder.php` → `src/Learners/Application/Service/`; `src/Sf1/Sf1Writer.php` → `src/Learners/Infrastructure/Sf1/`; `src/LearnerId/{IdCardPdf,QrCode}.php` → `src/Learners/Infrastructure/LearnerId/`; `templates/learners/id/*` → `src/Learners/Presentation/templates/learners/id/` (`@Learners/learners/id/...`)
- Create in `src/Learners/Domain/Service/`: `Sf1/Sf1SpreadsheetInterface` (`xls(Sf1Report): string`), `LearnerId/IdCardPdfInterface` (`render(list<IdCard>, IdLayout): string`), `LearnerId/IdCardImagesInterface` (`logo(string $path): ?string`, `photo(string $path): ?string`, `seal(): ?string`, `qr(string $text): string`), `LearnerId/IdCardFileName` (`forSection(Section): string`, today's `IdCardPdf::sectionFileName`)
- Create: `src/Learners/Infrastructure/LearnerId/IdCardImages.php` (the GD/webp, seal file and QR code parts of today's `IdCardBuilder`); `Sf1Writer` implements `xls()` (writes the `Spreadsheet` from `write()` to Xls bytes, as the download does today)
- Create queries (`Application/Query/`): `GetSf1FileCommand`, `GetSectionIdCardsCommand` (section, layout string), `GetLearnerIdCardCommand` (learner, layout string), each with `actorId`, `schoolId`, `fixedSectionId`; handlers check the page scope (404s) and the policy (`VIEW_CLASS`, `EDIT`; 403) and return `['fileName' => string, 'content' => string]`
- Modify: `src/Schools/Application/Api/SchoolDirectory{Interface,}.php` (`principalOf`); `Sf1ReportBuilder` and `IdCardBuilder` read the principal through it and the time through `SchoolClockInterface`
- Move tests: `tests/Service/{Sf1ReportBuilderTest,IdCardBuilderTest}.php` → `tests/Learners/Integration/Application/`; `tests/Service/IdCardPdfTest.php` → `tests/Learners/Integration/Infrastructure/`; `tests/Service/{Sf1WriterTest,LearnerIdQrCodeTest}.php` → `tests/Learners/Unit/Infrastructure/` (plain `TestCase`)
- Test (new): `tests/Learners/Integration/Application/DownloadQueriesTest.php` (each query's file name and non-empty content, its 403 and 404), a `principalOf` case in the Schools API test, `IdCardImages` cases not covered by the moved builder test

**Interfaces — Produces:** `Sf1ReportBuilder::build(Section): Sf1Report`; `IdCardBuilder::forSection(Section): list<IdCard>`, `forEnrollment(Enrollment): IdCard`; the three queries above.

- [ ] **Step 1:** Save the HEAD route dump and a baseline copy (scratchpad).
- [ ] **Step 2:** Move and write the tests above.
- [ ] **Step 3:** Implement.
- [ ] **Step 4: Setup checks:** `cache:clear`, `lint:container`, `lint:twig`.

### Task 2: Transfer use cases and queries

**Files:**
- Move: `src/Service/TransferManager.php` → `src/Learners/Application/Service/TransferRequests.php` (`request`, `approve`, `decline`, `cancel`; no lock, no flush; limits through `SchoolDirectoryInterface::blockedReason()` / `studentRefusal()`; checks through `EnrollmentRules`; "This request was already decided." on a request that is gone or no longer pending)
- Create commands (`UseCase/Command/`): `RequestTransferCommand` (`actorId`, `schoolId`, `learnerId`, `lrn`; public `?int $sectionId`), `ApproveTransferCommand` (`actorId`, `schoolId`, `transferId`, `learnerId`, `toSchoolId`), `DeclineTransferCommand`, `CancelTransferCommand` (`actorId`, `schoolId`, `transferId`, `learnerId`), and their handlers (locks first; the request handler repeats the LRN lookup and throws `TransferLookupChangedException` when the learner is no longer enrolled elsewhere; policy `REQUEST_TRANSFER` / `DECIDE_TRANSFER` / `CANCEL_TRANSFER`)
- Create queries (`Query/Command/`): `ListTransfersCommand` (`actorId`, `schoolId`, `?tab`; returns school, school year, tab, the adviser's section or null, and the requests with `canDecide` / `canCancel`; 403 as today), `GetTransferCommand` (`actorId`, `schoolId`, `transferId`, `outgoing`; 404 "Transfer request not found." as today's `find()`; for a decided request, today's page-access check; returns `learnerId`, `toSchoolId`, `pending`)
- Create: `Domain/Exception/{TransferNotFoundException,TransferLookupChangedException}.php`; `LearnerPolicy::managesSchool(User, School): bool` (admin, or the school's principal this year); `TransferRequestTransformer` gains the two flags
- Delete: `src/Service/{TransferManager,LearnerLock,EnrollmentGuard}.php`; `LearnerLock::BUSY` users (`src/Attendance/AttendanceRecorder.php`, `src/Grading/GradeLocks.php`, `src/Nutrition/NutritionRecorder.php`, `tests/Grading/GradeRecorderTest.php`) switch to `LearnerEnrollment::BUSY`; the three test comments naming `TransferManager::approve` name `TransferRequests::approve`
- Move tests: `tests/Service/TransferManagerTest.php` → `tests/Learners/Integration/Application/TransferRequestsTest.php` (through a flushing helper `SavingTransferRequests`; stale-copy cases cut, as in 3c); `tests/Learners/Integration/Presentation/LearnerVoterTest.php` → `tests/Learners/Integration/Application/LearnerPolicyTest.php`
- Test (new): `tests/Learners/Integration/Application/TransferHandlersTest.php` (locks first and the busy answer, decided meanwhile, lookup changed, 403/404 refusals, both queries' shapes)

- [ ] **Step 1:** Move and write the tests above.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `lint:container`.

### Task 3: Transfer and download screens

**Files:**
- Move: `src/Controller/SchoolTransferController.php` → `src/Learners/Presentation/Controller/` (same routes; approve/decline/cancel: `GetTransferCommand`, then today's "already decided" flash or the use case; request: `GetEnrollPageCommand`, then `RequestTransferCommand`, keeping today's order of 403, 404 "No current school year.", back to the enroll page, first form error as a flash); `templates/transfers/*` → `src/Learners/Presentation/templates/transfers/` (`is_granted` → the flags; the dashboards' `_waiting_card` include → `@Learners/transfers/_waiting_card.html.twig`)
- Move the download routes out of `src/Controller/LearnerDownloadController.php`: `school_learners_ids`, `school_learners_id`, `school_learners_sf1` into `SchoolLearnerController`; `teacher_class_sf1`, `teacher_class_ids`, `teacher_class_id` into `TeacherClassController` (advisory through `GetAdvisoryCommand`, as the class pages); a shared `download()` keeps today's headers. Delete `LearnerDownloadController`
- Create: `src/Learners/Presentation/Form/TransferRequestFormType.php`; the enroll page's transfer form uses it. Delete `src/Form/EnrollSectionFormType.php`, `src/Learners/Presentation/Security/LearnerVoter.php`, and the emptied `src/Sf1/`, `src/LearnerId/`, `templates/transfers/`, `templates/learners/`
- Move tests: `tests/Controller/SchoolTransferControllerTest.php` → `tests/Learners/Functional/`
- Test (new): controller cases for the busy answer and a request whose learner left meanwhile; a teacher who is no longer a teacher gets 403 on the three teacher downloads (already in `TeacherClassControllerTest`; keep passing)

- [ ] **Step 1:** Move and write the tests.
- [ ] **Step 2:** Implement.
- [ ] **Step 3: Setup checks:** `lint:container`, `lint:twig`, route table identical to HEAD.

### Task 4: Baselines, docs, comments, deprecations

- [ ] **Step 1:** phpcbf, then phpcs clean; LF line endings on every touched file; PHPStan on the module folders (clear cache), then baseline regeneration; deptrac 0; ESLint and Stylelint clean.
- [ ] **Step 2: Docs.** `CLAUDE.md`: transfers, SF1 and ID cards in Learners, `TransferRequests` and its locks, `principalOf` in the Schools API, what legacy is left (`CurrentLearnerGrades`, `TeacherClassGradesController`), no more `LearnerVoter` / `LearnerLock` / `EnrollmentGuard`.
- [ ] **Step 3: Comment sweep and deprecation check** (`debug:container --deprecations`) over everything touched.

### Task 5: Verification

- [ ] **Step 1: Scoped run** of the affected tests (`tests/Learners`, transfer, dashboard, attendance, grading, nutrition, card slip and Schools API tests), then fix, re-run only failures.
- [ ] **Step 2: Coverage** of `src/Learners` → nothing uncovered.
- [ ] **Step 3: Full runs on the final code, one at a time:** `make test all NO_PARALLEL=1` (fresh PHPStan), `make test-feature`, `make regression-test`; schema valid, diff empty; route table identical to HEAD.
- [ ] **Step 4: Fresh whole-change review** (most capable model, read-only); fix Critical/Important (and anything a user would see) with a failing test first; full runs again.
- [ ] **Step 5: Ask the owner before the live-browser check, and whether headless or visible.** If headless: HEAD vs branch on the same dev data (reset before each side): the transfers page as the principal (both tabs) and as the Individual-plan adviser, a request from the enroll page, approve, decline and cancel, an already-decided request, the dashboards' waiting card, and the SF1 and ID downloads as principal, adviser and admin (status, content type, file name, size); then restore the dev data.
- [ ] **Step 6: Report** and propose the commit; commit only after approval:

```
Move transfers, SF1 and learner IDs into the Learners module

Move transfer requests and their page, the SF1 export and the learner
ID cards into the Learners module, with every change through the bus.
The legacy learner lock, enrollment guard and learner voter are gone;
attendance, grading and nutrition keep the same busy message. Schools'
public API now gives a school's principal. Pages, messages, URLs and
downloads are unchanged.
```
