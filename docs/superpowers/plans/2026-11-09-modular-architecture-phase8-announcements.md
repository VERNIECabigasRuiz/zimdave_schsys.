# Modular architecture, Phase 8 (Announcements) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move announcements (the `Announcement` entity and the admin announcement screens) into an `Announcements` module with DDD layers, every action through the Core bus, without anything a user would notice changing.

**Architecture:** Same layout as Billing (Phase 2c), the closest match: a small admin CRUD.
- `Domain/Model/Announcement` (no `repositoryClass`, no validator attributes), `Domain/Model/Repository/AnnouncementRepositoryInterface`, `Domain/Exception/AnnouncementNotFoundException`.
- `Application/UseCase` (post, update, delete) and `Application/Query` (list, get), `Application/Transformer/AnnouncementTransformer`.
- `Infrastructure/Repository/DoctrineAnnouncementRepository`.
- `Presentation/{Controller,Form,templates}`; the form binds to the commands, which carry the old entity rules.
- The author is read through Access's `AccountDirectoryInterface::findById()`.
- Nothing outside the admin screens reads announcements today, so the module has no public API. The dashboards (Phase 9) will add one if they need it.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Twig, PHPUnit 13, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row 6 `Announcements`, sections 5, 6, 8, 10, 11).
- **Scope:** this ticket only migrates. Regressions the move causes are fixed; existing flaws are left (for example, nothing shows announcements to principals, teachers or students yet, though the list page says so).
- The plan is committed with the phase.

**Branch:** `feature/modular-architecture` (7b committed as `63f79fa`, notes as `a766760`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 0. Planning | 2026-11-09 14:11:17 | 14:14:22 | 3m 5s |
| 1. Domain, persistence and wiring | 14:14:22 | 14:21:02 | 6m 40s |
| 2. Announcement administration; legacy removed | 14:21:02 | 14:57:06 | 36m 4s (about 30m waiting for the container's asset build) |
| 3. Baselines, docs, comments, deprecations | 14:57:06 | 15:14:39 | 17m 33s |
| 4. Verification | 15:14:39 | 16:42:41 | 1h 28m 2s |

## Global Constraints

**Structure only.** These stay exactly as today:
- URLs, route names, methods and requirements: `admin_announcements` (GET `/admin/announcements`), `admin_announcement_new` (GET/POST `/new`), `admin_announcement_edit` (GET/POST `/{id}/edit`), `admin_announcement_delete` (POST `/{id}/delete`), `id` = `\d+`.
- The form name and fields (`announcement_form[title|audience|body]`), labels ("Title", "Who should see it?", "Message"), the audience choices and their order (Everyone, Principals, Teachers, Students), and the CSRF id `announcement-<id>`.
- Validation: title required, at most 150 characters; message required; audience one of `all`, `ROLE_PRINCIPAL`, `ROLE_TEACHER`, `ROLE_STUDENT`. A refused form answers 422. Title and message are trimmed, so blanks-only is refused.
- Flashes: "Announcement posted.", "Announcement saved.", "Announcement deleted.". Redirects to `/admin/announcements`.
- Activity-log lines: "Posted announcement: <title>", "Updated announcement: <new title>", "Deleted announcement: <title>".
- The list: newest first (created time, then id, both descending), with title, audience label, `M j, Y g:i A` time, " · <author full name>" only when the author still exists, the body with line breaks.
- The page headings ("New announcement", "Edit announcement"), button texts ("Post announcement", "Save changes") and the empty text.
- `createdAt` is set when the announcement is built, as today (`new \DateTimeImmutable()`).
- Table `announcement` and its columns, the author foreign key with `ON DELETE SET NULL`. `doctrine:migrations:diff` stays empty; the route table matches HEAD.

**Layers.**
- Announcements may use Core, Access_Domain (`User` and its role constants, the author relation) and Access_Api. Nothing else.
- Legacy no longer uses Announcements PHP code; the admin layout's nav link is a route name only.
- The Domain uses only `Doctrine\ORM\Mapping` and Access Domain; column types as strings (`'text'`), no `Doctrine\DBAL\Types\Types`.

**Bus rules.**
- The controller extends `BusController`, keeps the class-level `#[IsGranted(User::ROLE_ADMIN)]` and `#[Route('/admin/announcements')]`, and makes one bus call per action (plus the get query on edit).
- Handlers refuse by throwing a `DomainException`; they don't flush (the bus does). No locks: nothing here races in a way that matters today.
- Command log arrays carry ids and the audience, never the title or message.

**New code.** `declare(strict_types=1)`, PHPStan level 9 with no baseline entries, phpcs PSR-12 (lines ≤ 120), 100% coverage of `src/Announcements`.

**Tests.**
- The moved test keeps its assertions; only the way it reaches a value may change.
- New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first), with every setup line under `// Initialize`. Use `AdminTestCase` and `SchoolFixturesTrait`; check the trait before naming a helper; never name a helper after a `TestCase` method.
- Kernel tests go in Integration or Functional. After moving the test, check its class name and suite; `tests/Controller/Admin` keeps its other tests.

**Moving classes and helper scripts.**
- After moving, grep the short names `Announcement`, `AnnouncementRepository`, `AnnouncementFormType`, `AnnouncementController`, and grep templates and config for their full old names.
- Check for repeated and same-namespace imports; compare moved string literals with the originals.
- Reuse the helper scripts in the previous session's scratchpad (`C:\Users\belfr\AppData\Local\Temp\claude\6632a947cb4a2317\dee607f4-d4a4-4513-8587-b2e3dc8b87cd\scratchpad\`: `set_namespace.py`, `dedupe_uses.py`, `prune_uses.py`, `replace_text.py`, `wrap_long.py`, `prune_baseline.py`, `compare_routes.py`, `routes.py`, `to_lf.py`, `uncovered.py`, the live-check scripts) by copying them into this session's scratchpad. Write new ones with the Write tool, in LF.
- No inline heredocs, no `<<`, no `sed` for text with backslashes, no `git add -N`.
- Before the first code change: save the HEAD route dump and a copy of `config/phpstan-baseline.neon`.

**Running checks.**
- Tests are written per task and run once in Task 4, one command at a time, in Docker (`MSYS_NO_PATHCONV=1`). `TEST_TARGET` takes one class, method or path.
- Read each check's output; save long output to a file in the scratchpad.
- Clear `reports/phpstan/.cache` before a PHPStan run that counts. Run deptrac through `make test deptrac`.
- After fixing one checker's findings, rerun the others on the same files.
- Watch long runs directly. Log a task's end and the next task's start together; compute durations with a command.

**PHPStan baseline.** It may only shrink (the `AnnouncementFormType` entry goes). No entry may name a module folder.

**Live-browser check.** Headless, without asking (owner, 2026-11-09). Move the worktree `.superpowers/sdd/2026-11-07-modular-architecture-phase2d-schools/head` to `a766760` (it is at `832d2fa`). Start servers without any removal in the same command. Reset the dev data before each side and restore it after.

**Comments and commit.** Comments short and plain, with no Claude/Anthropic mention. One commit at the end after approval, through the Base hook, including this plan (`git add -f`).

## Review Focus

1. **Blanks and trimming.** A title or message of only spaces is refused (422) as today, and a padded title is saved and logged trimmed. The form's text fields trim by default; the commands keep `NotBlank`. Pinned by `testBlankOnlyTitleIsRefused` and `testTitleAndMessageAreSavedTrimmed` (Task 2).
2. **A deleted author.** The author's account is deleted (`SET NULL`): the list still shows the announcement, without " · <name>". Pinned by `testAnnouncementOfADeletedAuthorShowsNoName` (Task 2).
3. **Unknown or vanished ids.** Edit GET/POST and delete (valid token) of an unknown id answer 404, as the entity argument did. Pinned by `testUnknownAnnouncementIs404` (Task 2).
4. **The page after a refused edit.** It keeps "Edit announcement" and "Save changes", with the typed values. Pinned by `testRefusedEditKeepsTheEditPage` (Task 2).
5. **Order with equal times.** Two announcements posted in the same second list the later id first. Pinned by `AnnouncementRepositoryTest::testNewestFirstBreaksTiesById` (Task 1).

## Rulings in this plan

- A forged CSRF token on an unknown id gets the token refusal instead of a 404 (the id is no longer resolved before the check); a valid token on a deleted announcement still gets 404. Same ruling as Billing and Access.
- The post command takes the author's id from the controller (`$this->getUser()`); the handler loads the account through `AccountDirectoryInterface::findById()`. An author id with no account is a programming error, not a user refusal: the handler throws `\LogicException`.
- The legacy `App` Doctrine mapping on `src/Entity` and the empty `src/Entity`, `src/Repository` folders (only `.gitignore` left) stay until the Phase 9 clean-up; `src/Form` is removed now that it is empty (grep config for it first).

---

### Task 1: Domain, persistence and wiring

**Files:**
- Move: `src/Entity/Announcement.php` → `src/Announcements/Domain/Model/Announcement.php` (`App\Announcements\Domain\Model\Announcement`; `#[ORM\Entity]` without `repositoryClass`, `#[ORM\Table(name: 'announcement')]`, no `Assert`; type `'text'` as a string; constants `AUDIENCE_ALL`, `AUDIENCES`, getters/setters and the trimming setters unchanged)
- Create: `src/Announcements/Domain/Model/Repository/AnnouncementRepositoryInterface.php`, `src/Announcements/Infrastructure/Repository/DoctrineAnnouncementRepository.php`
- Delete: `src/Repository/AnnouncementRepository.php`
- Modify: `config/packages/doctrine.yaml` (mapping `Announcements`, shaped like `Staff`), `config/packages/twig.yaml` (`src/Announcements/Presentation/templates` as `Announcements`), `config/deptrac.yaml` (layers `Announcements_{Domain,Application,Infrastructure,Presentation}` shaped like LessonPlans' without an Api; rules above; the `Legacy` regex excludes `Announcements\\`), `phpunit.dist.xml` (`<source>` adds `src/Announcements`), `config/phpcs.xml` (adds `src/Announcements`, `tests/Announcements`), `tests/SchoolFixturesTrait.php` (import)
- Test: `tests/Announcements/Unit/Domain/AnnouncementTest.php`, `tests/Announcements/Integration/Infrastructure/AnnouncementRepositoryTest.php`

**Interfaces:**
- Produces `AnnouncementRepositoryInterface`: `findById(int $id): ?Announcement`, `findNewestFirst(): list<Announcement>` (created time desc, id desc), `add(Announcement $announcement): void`, `remove(Announcement $announcement): void` (no flush in either).

- [ ] **Step 1: Write the tests.**
  - `AnnouncementTest`: title and body trimmed; audience label for each audience, and the raw value for an unknown one; `createdAt` set on construction; author set and cleared.
  - `AnnouncementRepositoryTest` (KernelTestCase, `SchoolFixturesTrait` wipes announcements): `testFindsById` (unknown → null), `testNewestFirstBreaksTiesById` (two rows with the same `createdAt` set through reflection, plus an older one: order newest, then higher id), `testAddAndRemoveWaitForFlush`.
- [ ] **Step 2: Move the entity, add the repository and the config.**
- [ ] **Step 3: Setup checks:** `cache:clear`, `lint:container`, `doctrine:schema:validate`, `doctrine:migrations:diff --allow-empty-diff -n` → no changes.

### Task 2: Announcement administration; legacy removed

**Files:**
- Create:
  - `src/Announcements/Application/UseCase/Command/{PostAnnouncementCommand,UpdateAnnouncementCommand,DeleteAnnouncementCommand}.php` and their handlers in `UseCase/Handler/`.
  - `src/Announcements/Application/Query/Command/{ListAnnouncementsCommand,GetAnnouncementCommand}.php` and their handlers in `Query/Handler/`.
  - `src/Announcements/Application/Transformer/AnnouncementTransformer.php`, `src/Announcements/Domain/Exception/AnnouncementNotFoundException.php`.
- Move:
  - `src/Controller/Admin/AnnouncementController.php` → `src/Announcements/Presentation/Controller/AnnouncementController.php` (extends `BusController`, same class attributes, routes and CSRF expression rewritten to `'"announcement-" ~ args["id"]'`).
  - `src/Form/AnnouncementFormType.php` → `src/Announcements/Presentation/Form/AnnouncementFormType.php` (same class name, so the form name stays; `data_class` is either command; choices from `Announcement::AUDIENCES`; `@extends AbstractType<PostAnnouncementCommand|UpdateAnnouncementCommand>`).
  - `templates/admin/announcements/{index,form}.html.twig` → `src/Announcements/Presentation/templates/admin/announcements/` (still extending `admin/_layout.html.twig`; the list reads `a.authorName` and `a.audienceLabel` from the array).
  - `tests/Controller/Admin/AnnouncementControllerTest.php` → `tests/Announcements/Functional/AnnouncementControllerTest.php` (`App\Tests\Announcements\Functional`, still extends `App\Tests\Controller\Admin\AdminTestCase`).
- Delete: `src/Form` (empty after the move), `templates/admin/announcements`.
- Test (new): `tests/Announcements/Unit/Application/AnnouncementHandlersTest.php`, new cases in `AnnouncementControllerTest`.

**Interfaces:**
- Consumes: Task 1; `AccountDirectoryInterface::findById(int $id): ?User`; `ActivityLoggerInterface::log(string)`.
- Produces:
  - `AnnouncementTransformer::toArray(Announcement $a): array{id: int, title: string, body: string, audience: string, audienceLabel: string, authorName: ?string, createdAt: \DateTimeImmutable}` (phpstan type `AnnouncementData`).
  - `PostAnnouncementCommand` (`public function __construct(public readonly int $authorId)`; public `string $title = ''` with `NotBlank`, `Length(max: 150)`; `string $audience = Announcement::AUDIENCE_ALL` with `Choice` over the four values; `string $body = ''` with `NotBlank`; `toLogArray()`: `authorId`, `audience`) → adds the announcement with the author, logs "Posted announcement: <title>", returns `null`.
  - `UpdateAnnouncementCommand` (same fields, `public readonly int $id`, `static fromAnnouncement(array $announcement): self` taking `AnnouncementData`; `toLogArray()`: `id`, `audience`) → `AnnouncementNotFoundException` (code `ANNOUNCEMENT_NOT_FOUND`), applies the fields, logs "Updated announcement: <title>", returns `null`.
  - `DeleteAnnouncementCommand(int $id)` (`toLogArray()`: `id`) → `AnnouncementNotFoundException`, logs "Deleted announcement: <title>", removes, returns `null`.
  - `ListAnnouncementsCommand()` → `list<AnnouncementData>`, newest first. `GetAnnouncementCommand(int $id)` → `AnnouncementData` or `AnnouncementNotFoundException` (controller 404). Neither is `#[Cacheable]`.
  - Controller: flashes and redirects as today; the not-found exception on update and delete becomes a 404; templates receive `announcements` (list) and `form` plus `announcement` (array or null).

- [ ] **Step 1: Move the test and write the new ones.**
  - Moved cases unchanged, except the final count reads `AnnouncementRepositoryInterface` (or `$this->em->getRepository(Announcement::class)` with the new class).
  - `AnnouncementControllerTest` new cases:
    - `testBlankOnlyTitleIsRefused`: title `'   '`, message filled → 422, nothing saved.
    - `testTitleAndMessageAreSavedTrimmed`: `'  Grades due  '` and `"  Friday  "` → stored `Grades due` / `Friday`; log line "Posted announcement: Grades due".
    - `testTooLongTitleIsRefused`: 151 characters → 422; 150 is accepted.
    - `testAnnouncementOfADeletedAuthorShowsNoName`: an announcement by a created user, user removed → list shows the title, no " · " and no name.
    - `testUnknownAnnouncementIs404`: edit GET and POST of an unknown id → 404; delete with a valid token taken from the list page after removing the row → 404.
    - `testRefusedEditKeepsTheEditPage`: edit with a blank title → 422, `h1` "Edit announcement", button "Save changes", the typed message in the textarea, the stored title unchanged.
    - `testUpdateLogsTheNewTitle`: "Updated announcement: <new title>" in `activityActions()`.
  - `AnnouncementHandlersTest` (unit, with in-memory fakes or mocks of the repository, directory and logger): post (author set, audience kept, log line, `toLogArray` without title/body), update (fields applied, not-found), delete (removed, log line, not-found), list (order passed through and mapped), get (found, not-found), transformer (`authorName` null without an author).
- [ ] **Step 2: Implement** the commands, handlers, transformer, exception, controller, form and templates (`@Announcements/admin/announcements/...`).
- [ ] **Step 3: Setup checks:** `cache:clear`, `lint:container`, `lint:twig` on every template folder, and the route table identical to HEAD (`compare_routes.py`).

### Task 3: Baselines, docs, comments, deprecations

- [ ] **Step 1: Static checks.** phpcbf, then phpcs clean, with LF line endings on touched files. PHPStan on `src/Announcements tests/Announcements` (cache cleared), then everything; prune the `AnnouncementFormType` baseline entry and confirm the baseline only shrank. `make test deptrac`: 0 violations, 0 uncovered.
- [ ] **Step 2: Docs.** `CLAUDE.md`: an `Announcements` paragraph after Staff (entity `App\Announcements\Domain\Model\Announcement`, repository interface, the admin screens `admin_announcement*` on the bus, author through the Access API, no locks, no public API); the coverage list under Tests adds `src/Announcements`.
- [ ] **Step 3: Comment sweep and deprecation check** (`debug:container --deprecations`) over everything touched.

### Task 4: Verification

- [ ] **Step 1: Scoped run.** `tests/Announcements`, then `tests/Controller/Admin/DashboardControllerTest.php` (the nav and the `/admin/announcements` access case), then `tests/DataFixtures` if it touches the trait. Fix, then re-run only the failures.
- [ ] **Step 2: Coverage** of `src/Announcements` on the module tests alone (`uncovered.py`): nothing uncovered.
- [ ] **Step 3: Guard breaks.** Turn off trimming on the title field (`'trim' => false`) and watch `testBlankOnlyTitleIsRefused` fail; drop the author-null guard in the transformer and watch the deleted-author case fail. Restore both.
- [ ] **Step 4: Full runs on the final code, one at a time:** `make test all`, then `make test-feature` (100.00%), then `make regression-test`. Schema valid, diff empty, routes identical.
- [ ] **Step 5: Fresh whole-change review** (most capable model, read-only). Fix Critical and Important findings, and anything a user would see, with a failing test first; then the full runs again.
- [ ] **Step 6: Headless live check**, HEAD against the branch on the same dev data, then restore the data: the empty list, posting (a refused blank form, then a good one with line breaks), the list entry, a refused edit, a good edit, delete, the admin dashboard nav.
- [ ] **Step 7: Report** and propose the commit (code and this plan). Commit only after approval:

```
Move announcements into the Announcements module

Move the announcement entity and the admin announcement screens into an
Announcements module whose actions all go through the bus. The form now
binds to the post and update commands, which carry the old rules.
Pages, messages and URLs are unchanged.
```
