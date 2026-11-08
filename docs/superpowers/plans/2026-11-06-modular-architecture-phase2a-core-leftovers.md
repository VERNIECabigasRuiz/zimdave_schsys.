# Modular architecture, Phase 2a (Core leftovers) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the shared, non-business pieces into `src/Core` (activity log, school clock, image storage, PDF rendering, the base layout) behind Domain interfaces, so the Access, Billing and Schools plans can use them from their own layers. Nothing a user sees changes.

**Architecture:** Following ams-core-02's layout, entities live in `Core/Domain/Model`, repository ports in `Core/Domain/Model/Repository`, other ports in `Core/Domain/Service/<Area>`, implementations in `Core/Infrastructure/<Area>`. Legacy code switches its imports to the Domain interfaces (legacy may use Core; Core uses nothing outside itself). The admin "Recent activity" menu reads through the query bus.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Symfony Messenger (Core bus), Dompdf, GD, Twig, PHPUnit 13, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row `Core`, sections 5, 6, 8, 10). Phase 2 is split into four plans by the owner (2026-11-06): this one, then Access, Billing, Schools.

**Branch:** `feature/modular-architecture` (Phase 1 committed as `f49d7c0`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 1. Activity log | 21:20:02 | 21:23:16 | 3m |
| 2. School clock | 21:24:08 | 21:24:33 | 1m |
| 3. Image storage | 21:24:33 | 21:27:24 | 3m |
| 4. PDF rendering | 21:27:24 | 21:28:28 | 1m |
| 5. Shared templates | 21:28:28 | 21:29:47 | 1m |
| 6. Baselines, docs, comments, deprecations | 21:29:47 | 21:37:07 | 7m |
| 7. Verification | 21:37:07 | 22:40:57 | 64m |

## Global Constraints

- Structure only, no behaviour change: every page, URL, route name, form, PDF and spreadsheet works as today.
- Table and column names stay the same; `doctrine:migrations:diff` stays empty and `doctrine:schema:validate` in sync.
- Core uses no module and no legacy class (`App\Entity`, `App\Service`, ...); legacy may use Core. deptrac enforces it.
- New Core code: `declare(strict_types=1)`, PHPStan level 9 with no baseline entries, phpcs PSR-12, 100% coverage of `src/Core`.
- Moved code keeps its behaviour line for line; where strict types or level 9 force a change (a GD function returning `false`), handle the `false` explicitly with the same outcome as today.
- Tests that move keep their assertions; only imports, class names and arguments change. New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first) and the existing helpers.
- Write each task's tests during the task; **don't run tests per task**. All runs happen in Task 7, one command at a time.
- Tooling in Docker only (`make bash-exec CMD='...'`, `make test`, `make test-feature`, `make regression-test`); never `make run`/`make stop`. In Git Bash use `MSYS_NO_PATHCONV=1` for `docker exec` with container paths.
- The PHPStan baseline may only shrink: after regenerating it, its total error count (the sum of its `count:` values, recorded in Task 6 Step 1) must be lower than or equal to before.
- Comments: short, plain, only where needed, no references to plans or tools. No Claude/Anthropic mention anywhere.
- One commit at the end, after explicit approval, through the Base commit message hook (`core.hooksPath` is set locally).

## Review Focus

1. **Activity entries still saved by legacy code.** Managers call `log()` and flush later; the entry must still land in `activity_log` on that flush. Pinned by the existing `AdminTestCase::activityActions()` tests and `ActivityLoggerTest::testEntryIsSavedOnTheCallersFlush` (Task 1).
2. **The admin top bar on an empty activity log.** A fresh install has no entries; the "!" menu must render empty, not fail. Pinned by `GetRecentActivityHandlerTest::testNoEntriesGivesAnEmptyList` (Task 1).
3. **Strict types in moved image code.** GD functions return `false` on failure; under `strict_types` passing `false` on is a TypeError instead of today's silent `0`. Pinned by PHPStan level 9 on `src/Core` and `ImageProcessorTest` (Task 3).
4. **Uploads whose temp file has no extension.** PHP stores uploads as `/tmp/phpAbC123`; the store methods now take a path. Pinned by `ImageProcessorTest::testStoresAFileWithoutExtension` (Task 3).
5. **Paper sizes of every PDF.** A4 (PDS), long bond (service record, big staff ID), letter (small staff ID), letter landscape (lesson plan) and the template's `@page` (card slips, learner IDs) must stay. Pinned by `DompdfRendererTest` (Task 4) and the existing PDF tests.

## Rulings in this plan

- **No XLSX adapter.** The SF1/SF2/SF8 writers build sheets with PhpSpreadsheet's full API; a Core wrapper would only re-export it. They keep using PhpSpreadsheet from their own module's Infrastructure when they move (deptrac allows Infrastructure → Vendor).
- **Role layouts stay** (`admin/_layout`, `principal/_layout`, `teacher/_layout`, `student/_layout`, `profile/_layout`): they hold each role's navigation and call legacy Twig functions; they move with Dashboards in Phase 5. Only `base.html.twig` and the flash partial move to `@Core`.
- **`AppExtension` keeps** `has_teaching_load`, `has_id`, `advises_section` (they read Schools/Classes data) until those modules move; `activity_time` and `latest_activity` move to Core.
- **The admin dashboard controller** (Phase 5) keeps reading entities, through `ActivityLogRepositoryInterface` instead of the old repository.

---

### Task 1: Activity log

**Files:**
- Create: `src/Core/Domain/Model/ActivityLog.php`, `src/Core/Domain/Model/Repository/ActivityLogRepositoryInterface.php`, `src/Core/Domain/Service/ActivityLog/ActivityLoggerInterface.php`, `src/Core/Infrastructure/Repository/DoctrineActivityLogRepository.php`, `src/Core/Infrastructure/ActivityLog/ActivityLogger.php`, `src/Core/Application/Query/Command/GetRecentActivityCommand.php`, `src/Core/Application/Query/Handler/GetRecentActivityHandler.php`, `src/Core/Application/Transformer/ActivityLogTransformer.php`, `src/Core/Presentation/Twig/ActivityExtension.php`
- Delete: `src/Entity/ActivityLog.php`, `src/Repository/ActivityLogRepository.php`, `src/Service/ActivityLogger.php`
- Modify: `config/packages/doctrine.yaml` (mapping `Core`), `src/Twig/AppExtension.php` (drop `activity_time`, `latest_activity` and the repository), every `ActivityLogger`/`ActivityLogRepository` user (23 in `src`, 12 in `tests`: `grep -rlE 'ActivityLog(ger|Repository)' src tests`), `tests/Core/Integration/Bus/Fixture/LogThenFailHandler.php`
- Test: `tests/Core/Unit/Application/Transformer/ActivityLogTransformerTest.php`, `tests/Core/Unit/Application/Query/Handler/GetRecentActivityHandlerTest.php`, `tests/Core/Unit/Presentation/Twig/ActivityExtensionTest.php`, `tests/Core/Integration/Infrastructure/Repository/DoctrineActivityLogRepositoryTest.php`, `tests/Core/Integration/Infrastructure/ActivityLog/ActivityLoggerTest.php`

**Interfaces:**
- Produces:
  - `App\Core\Domain\Model\ActivityLog` — same mapping as today (table `activity_log`, index on `created_at`), no `repositoryClass`; `__construct(string $username, string $action)`, `getId(): ?int`, `getUsername(): string`, `getAction(): string`, `getCreatedAt(): \DateTimeImmutable`.
  - `ActivityLogRepositoryInterface`: `record(ActivityLog $entry): void` (persist, no flush), `findRecent(int $limit): list<ActivityLog>` (newest first, then highest id).
  - `ActivityLoggerInterface`: `log(string $action, ?string $username = null): void` — same contract as today (signed-in user's identifier, else `'system'`; saved on the caller's next flush).
  - `GetRecentActivityCommand(int $limit)` implements `Query`; `toLogArray()` returns `['limit' => $limit]`.
  - `GetRecentActivityHandler` (`#[AsQueryHandler(GetRecentActivityCommand::class)]`) → `Result::success(list<array{username: string, action: string, createdAt: \DateTimeImmutable}>)`.
  - `ActivityLogTransformer::toArray(ActivityLog $entry): array{username: string, action: string, createdAt: \DateTimeImmutable}` (static).
  - `ActivityExtension`: Twig filter `activity_time` (body moved unchanged) and function `latest_activity(int $limit = 6)` (one `QueryBusInterface::handle()`; returns `data()`, rethrows `exception()` on failure so errors surface as today).

- [ ] **Step 1: Write the tests.**
  - `ActivityLogTransformerTest::testShapesAnEntry`: `toArray(new ActivityLog('ana', 'Signed in'))` has keys `username` `'ana'`, `action` `'Signed in'`, `createdAt` the entry's own `getCreatedAt()`.
  - `GetRecentActivityHandlerTest` (stub repository): `testReturnsTheNewestEntriesShaped` (repository called with `6`, data is the transformed list in repository order); `testNoEntriesGivesAnEmptyList` (`[]`, success).
  - `ActivityExtensionTest`: move today's `activity_time` cases if any exist (`grep -rn activity_time tests`); otherwise `testTimeToday` (`'g:i A'`), `testYesterday` (`'Yesterday'`), `testOlder` (`'M j, Y'`); `testLatestActivityReturnsTheQueryData` (bus stub returns success `[['username' => 'ana', ...]]`); `testLatestActivityRethrowsAFailure` (`// Expect` `\RuntimeException`, bus returns `Result::failure(new \RuntimeException(), 'INTERNAL_ERROR')`).
  - `DoctrineActivityLogRepositoryTest` (KernelTestCase, deletes its own rows by username `'core-test'` in `setUp`): `testFindRecentIsNewestFirstAndLimited` (three entries, limit 2 → the two newest, ties broken by higher id); `testRecordWaitsForFlush` (after `record()` without flush, a DBAL count is `0`; after `flush()`, `1`).
  - `ActivityLoggerTest` (KernelTestCase): `testUsesTheSignedInUser` (token storage holds `UsernamePasswordToken(new InMemoryUser('ana', null), 'main')` → username `'ana'`); `testUsesTheGivenUsername`; `testFallsBackToSystem` (no token → `'system'`); `testEntryIsSavedOnTheCallersFlush`.
- [ ] **Step 2: Create the Domain classes** with the signatures above. Move the entity's docblock as is.
- [ ] **Step 3: Create the Infrastructure classes.** `DoctrineActivityLogRepository extends ServiceEntityRepository<ActivityLog>` implements the interface (`findRecent` body moved unchanged; `record()` = `getEntityManager()->persist()`). `ActivityLogger` takes `ActivityLogRepositoryInterface` and `Security` (body moved, persist through `record()`).
- [ ] **Step 4: Create the query, handler, transformer and `ActivityExtension`.**
- [ ] **Step 5: Add the Doctrine mapping** in `config/packages/doctrine.yaml` next to `App`: `Core: { type: attribute, is_bundle: false, dir: '%kernel.project_dir%/src/Core/Domain', prefix: 'App\Core\Domain', alias: Core }`.
- [ ] **Step 6: Switch legacy users** to `ActivityLoggerInterface` / `ActivityLogRepositoryInterface` (type hints and imports only), trim `AppExtension`, update `LogThenFailHandler`, delete the three old files.
- [ ] **Step 7: Check the schema is unchanged** (setup check, not a test run): `make bash-exec CMD='php bin/console doctrine:migrations:diff --allow-empty-diff -n'`. Expected: "No changes detected".

### Task 2: School clock

**Files:**
- Create: `src/Core/Domain/Service/Clock/SchoolClockInterface.php`, `src/Core/Infrastructure/Clock/SchoolClock.php`
- Delete: `src/Service/SchoolClock.php`
- Modify: the 17 users in `src` (`grep -rl 'Service\\SchoolClock' src`): type hints to `SchoolClockInterface`; the 9 tests that build one (`new SchoolClock(...)`): import the Infrastructure class.
- Test: `tests/Core/Unit/Infrastructure/Clock/SchoolClockTest.php`

**Interfaces:**
- Produces: `SchoolClockInterface::now(): \DateTimeImmutable`; `App\Core\Infrastructure\Clock\SchoolClock implements SchoolClockInterface`, constructor unchanged (`ClockInterface $clock`, `#[Autowire('%app.timezone%')] string $timezone`).

- [ ] **Step 1: Write `SchoolClockTest::testNowIsOnTheSchoolsWallClock`**: `new SchoolClock(new MockClock('2026-11-06 16:30:00 UTC'), 'Asia/Manila')`, `now()->format('Y-m-d H:i e')` is `'2026-11-07 00:30 Asia/Manila'`.
- [ ] **Step 2: Create the interface and class** (body moved unchanged), switch the users, delete the old file.

### Task 3: Image storage

**Files:**
- Create: `src/Core/Domain/Service/FileStorage/ImageProcessorInterface.php`, `src/Core/Domain/Exception/ImageProcessingException.php`, `src/Core/Infrastructure/FileStorage/ImageProcessor.php`
- Delete: `src/Service/ImageProcessor.php`, `src/Service/ImageProcessingException.php`
- Move: `tests/Service/ImageProcessorTest.php` → `tests/Core/Integration/Infrastructure/FileStorage/ImageProcessorTest.php`
- Modify: users in `src` (`DivisionController`, `SchoolProfileController`, `ProfileController`, `AbstractLearnerController`, `ImageUploadFormType`, `PdsPdfRenderer`, `LearnerEnrollment`, `CardSlipPdf`, `IdCardBuilder`, `StaffIdCardBuilder`) and `tests` (`ProfileControllerTest`, `PdsPdfRendererTest`, `IdCardBuilderTest`, `LearnerEnrollmentTest`, `CardSlipPdfTest`, `StaffIdCardBuilderTest`).

**Interfaces:**
- Produces:
  - `ImageProcessorInterface`: `storePhoto(string $sourcePath, ?string $oldPath): string`, `storeLogo(string $sourcePath, ?string $oldPath): string`, `storeSignature(string $sourcePath, ?string $oldPath): string`, `remove(?string $path): void`, `absolutePath(string $path): string`. The constants (`PHOTO_WIDTH`, `PHOTO_HEIGHT`, `LOGO_MAX`, `SIGNATURE_MAX`, `MAX_PIXELS`) move to the interface.
  - `ImageProcessingException extends DomainException`, error code `IMAGE_NOT_ACCEPTED`, messages unchanged.
  - Callers pass `$file->getPathname()` where they passed the `File`.

- [ ] **Step 1: Move the test** (assertions unchanged; pass `->getPathname()`), and add `testStoresAFileWithoutExtension` (copy a JPEG to `$this->tmp.'/phpAbC123'`, `storePhoto()` returns a path matching `#^uploads/photos/[0-9a-f]{32}\.webp$#`) and `testRejectionCarriesItsErrorCode` (the "not a readable image" case; `errorCode()` is `'IMAGE_NOT_ACCEPTED'`).
- [ ] **Step 2: Create the interface, exception and class** (reads the source by path; GD `false` returns handled so level 9 passes with today's outcomes), switch the users, delete the old files. Controllers keep catching `ImageProcessingException` by its new name.

### Task 4: PDF rendering

**Files:**
- Create: `src/Core/Domain/Service/Pdf/PdfRendererInterface.php`, `src/Core/Infrastructure/Pdf/DompdfRenderer.php`
- Modify: `src/Service/PdsPdfRenderer.php`, `src/CardSlip/CardSlipPdf.php`, `src/LearnerId/IdCardPdf.php`, `src/LessonPlan/LessonPlanPdf.php`, `src/ServiceRecord/ServiceRecordPdf.php`, `src/StaffId/StaffIdPdf.php` (inject the interface; replace their Dompdf block with one `render()` call); tests that build these classes by hand, if any (`grep -rnE 'new (PdsPdfRenderer|CardSlipPdf|IdCardPdf|LessonPlanPdf|ServiceRecordPdf|StaffIdPdf)\(' tests`).
- Test: `tests/Core/Unit/Infrastructure/Pdf/DompdfRendererTest.php`

**Interfaces:**
- Produces: `PdfRendererInterface::render(string $html, string|array|null $paper = null, string $orientation = 'portrait'): string` (`$paper` is `string|list<float|int>|null` in PHPDoc; `null` leaves the paper to the HTML's `@page`). Options as today: remote off, default font `DejaVu Sans`.

- [ ] **Step 1: Write `DompdfRendererTest`**: `testRendersAPdf` (output starts with `'%PDF-'`); `testPaperSizes` with a data provider: `'A4'` → `/MediaBox [0.000 0.000 595.280 841.890]`; `[0, 0, 612, 936]` → `612.000 936.000`; `[0, 0, 792, 612]` → `792.000 612.000`; `[0, 0, 612, 792]` → `612.000 792.000`; `testPageRuleDecidesWithoutPaper` (`<style>@page { size: 612pt 936pt; }</style>`, `null` paper → `612.000 936.000`).
- [ ] **Step 2: Create the interface and class, switch the six PDF classes** (each keeps its own paper value; `CardSlipPdf` and `IdCardPdf` pass `null`).

### Task 5: Shared templates

**Files:**
- Move: `templates/base.html.twig` → `src/Core/Presentation/templates/base.html.twig`; `templates/admin/_flashes.html.twig` → `src/Core/Presentation/templates/_flashes.html.twig`
- Modify: `config/packages/twig.yaml` (`paths: { '%kernel.project_dir%/src/Core/Presentation/templates': Core }`), the six `{% extends 'base.html.twig' %}` and the four `admin/_flashes.html.twig` includes (`@Core/base.html.twig`, `@Core/_flashes.html.twig`).

- [ ] **Step 1: Move and repoint.** Check nothing else names the old paths: `grep -rn "base.html.twig\|_flashes.html.twig" templates src tests config`. Expected: only `@Core/...` references.
- [ ] **Step 2: Lint** (setup check): `make bash-exec CMD='php bin/console lint:twig templates src/Core/Presentation/templates'`. Expected: all OK.

### Task 6: Baselines, docs, comments, deprecations

- [ ] **Step 1: Record the PHPStan baseline's total `count`** (sum of all `count:` values) before regenerating.
- [ ] **Step 2: Regenerate the baselines** one at a time (warm the dev cache first: `make bash-exec CMD='php bin/console cache:warmup --env=dev'`):
  `make bash-exec CMD='vendor/bin/phpstan analyse -c config/phpstan.neon --memory-limit=1G --generate-baseline config/phpstan-baseline.neon'`, then the deptrac one as in Phase 1. Expected: the PHPStan total is not higher than Step 1's; no entry names a `src/Core` or `tests/Core` path; the deptrac baseline stays `skip_violations: {}`.
- [ ] **Step 3: Update docs.** `CLAUDE.md`: Core now holds the activity log, school clock, image storage and PDF rendering (use the Domain interfaces `ActivityLoggerInterface`, `SchoolClockInterface`, `ImageProcessorInterface`, `PdfRendererInterface`); the upload gotcha names `ImageProcessorInterface`; `@Core` holds `base.html.twig` (fix the `turbo-prefetch` gotcha's path). `AGENTS.md` only if a command changed.
- [ ] **Step 4: Comment clean-up and deprecation check** over every touched file: comments short and plain, no stale names (`App\Service\...`) in docblocks; `make bash-exec CMD='php bin/console debug:container --deprecations'` shows nothing new.

### Task 7: Verification

- [ ] **Step 1: Scoped run first** (only what this plan touched): `make bash-exec CMD='bash docker/test/run-feature-tests.sh tests/Core tests/Controller/ProfileControllerTest.php tests/Controller/Admin tests/Service tests/CardSlip'`. Fix, re-run only failures.
- [ ] **Step 2: Full runs on the final code, one at a time:** `make test all` (8/8), `make test-feature` (all suites, coverage 100.00% of `src/Core`), `make regression-test` (5 browser tests).
- [ ] **Step 3: Schema and deptrac**: `make bash-exec CMD='php bin/console doctrine:schema:validate'` (in sync), `doctrine:migrations:diff` empty (as Task 1 Step 7).
- [ ] **Step 4: Ask the owner before any live-browser check** (team notes). If approved, repeat the develop-vs-branch screenshot comparison and the controller checks from Phase 1's verification, headless in the web container.
- [ ] **Step 5: Clean up and report**: no stray processes or scratch files; show the timeline, the change list, mistakes with proposed notes, and the proposed commit message. Wait for approval:

```
Move shared services into Core

Move the activity log, school clock, image storage and PDF rendering
into Core behind Domain interfaces, read the admin activity menu
through the query bus, and serve the base layout from the Core
templates. Pages, PDFs and stored images are unchanged.
```
