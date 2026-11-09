# Modular architecture, Phase 9b (PHP and tooling clean-up) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the PHP side of the move. Nothing legacy is left in `src/` or `tests/`; the `Legacy` deptrac layer, both baselines, the `legacy` test suite and the legacy Doctrine mapping are gone; every PHP file is under phpcs and the 100% coverage gate. The two problems found on the way are fixed: the browser test that fails at HEAD and Tailwind scanning the whole project during the asset build. Nothing a user would notice changes.

**Architecture:**
- **Demo fixtures** stay in `src/DataFixtures` as dev tooling (owner, 2026-11-09). They get their own deptrac layer, `DevFixtures`, which may use Core and every module's Domain, Application and Api; nothing may use it. The fixtures bundle is loaded only in `dev` and `test`.
- **Test helpers:** `AdminTestCase` moves from the legacy `tests/Controller/Admin/` to `tests/Support/AdminTestCase.php` (`App\Tests\Support`). `SchoolFixturesTrait` stays at `tests/SchoolFixturesTrait.php`. `KernelTimezoneTest` moves to `tests/Core/Integration/`, `EnrollmentRepositoryTest` to `tests/Learners/Integration/Infrastructure/`.
- **SF2/SF8 Excel templates** move next to the writers that fill them: `src/Attendance/Infrastructure/Sf2/sf2-template.xlsx`, `src/Nutrition/Infrastructure/Sf8/sf8-template.xlsx`. `templates/` is then empty and removed.
- **Checker scopes** become whole folders: phpcs `../src` and `../tests`; PHPUnit coverage `src` (the whole folder).

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, PHPUnit 13, Panther, PHPStan 2.3, deptrac 4.7, phpcs 4.0, Vite 8, Tailwind 4.3.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (sections 5 "When the last module has moved, the Legacy layer and the baseline are deleted", 8 "at the end everything is covered", 11 phase 5).
- **Owner decisions (2026-11-09):**
  - Phase 9 is 9a (Dashboards, committed `a9aaa53`), 9b (this plan) and 9c (front-end: the legacy Stimulus controllers and stylesheets into modules, under ESLint/Stylelint, with Vitest tests).
  - Fixtures stay as dev tooling with their own layer.
  - The browser test and the Tailwind scan are fixed here.
  - The owner merges into `develop` after 9c.
- **Scope:** structure only, plus the two fixes above.
- The plan is committed with the phase.

**Branch:** `feature/modular-architecture` (9a committed as `a9aaa53`, notes as `5a7ddeb`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 0. Planning | 2026-11-09 19:21:39 | 19:35:44 | 14m 5s |
| 1. Tests, fixtures and Excel templates into place | 19:35:44 | 19:44:14 | 8m 30s |
| 2. Legacy layer, baselines, mappings and checker scopes | 19:44:14 | 20:04:59 | 20m 45s |
| 3. The browser test that fails at HEAD | 20:04:59 | 20:20:42 | 15m 43s |
| 4. Tailwind's source scan | 20:20:42 | 20:23:51 | 3m 9s |
| 5. Docs, comments, deprecations | 20:23:51 | 20:29:03 | 5m 12s |
| 6. Verification | 20:29:03 | 22:11:28 | 1h 42m 25s |

## Global Constraints

**Structure only.**
- URLs, pages, downloads (the SF2 and SF8 files byte for byte apart from their dates), the demo data and the built CSS stay as they are.
- `doctrine:migrations:diff` stays empty; the route table matches HEAD.

**Layers.**
- No `Legacy` layer, no deptrac baseline, no PHPStan baseline.
- `DevFixtures` may use Core (all layers) and every module's Domain, Application and Api; nothing may use `DevFixtures`.
- `Kernel` keeps its layer.

**New code and moved code.** `declare(strict_types=1)`, PHPStan level 9 with no baseline, phpcs PSR-12 (lines ≤ 120) over all of `src` and `tests`, 100% coverage of all of `src`.

**Tests.**
- Moved tests keep their assertions. New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first), every setup line under Initialize, Execute runs the code under test, ids compared rather than entities.
- After moving a test: check class name, namespace, `__DIR__` paths and suite.
- No new browser tests; the browser test is fixed, not rewritten.

**Moving files and helper scripts.**
- After moving, grep the old full names and paths (`App\Tests\Controller\Admin\AdminTestCase`, `App\Tests\KernelTimezoneTest`, `App\Tests\Repository`, `templates/forms`, `src/Entity`, `App\Entity`) in `src`, `tests`, `config`, `docker`, `.claude`, `CLAUDE.md`, `AGENTS.md`.
- Run checkers on every file a scripted rewrite touched (the whole phpcs scope), not a hand-picked list.
- Helper scripts in the scratchpad, written with the Write tool, LF, run with `python3`. No inline heredocs, no `<<`, no `sed` for text with backslashes, no `git add -N`. Use `git -C` for the worktree.
- Before the first change: save the HEAD route dump, a copy of both baselines, and the built CSS file name and hash (`public/build/assets/app-*.css`).

**Running checks.**
- Tests are written per task and run once in Task 6, one at a time, in Docker (`MSYS_NO_PATHCONV=1`). The browser test's evidence runs in Task 3 are part of that task's debugging, not verification.
- Read each output; save long output to a scratchpad file. Clear `reports/phpstan/.cache` before a PHPStan run that counts. deptrac through `make test deptrac`.
- After fixing one checker's findings, rerun the others on the same files. Watch long runs directly. Log task ends and starts together; compute durations with a command.

**Live-browser check.** Headless, without asking (owner). Worktree `.superpowers/sdd/2026-11-07-modular-architecture-phase2d-schools/head` to `5a7ddeb`. Servers started without removals in the same command; dev data reset per side and restored after.

**Comments and commit.** Short, plain comments with no Claude/Anthropic mention. One commit at the end after approval, through the Base hook, including this plan (`git add -f`).

## Review Focus

1. **The SF2 and SF8 downloads.** The writers find their template at the new path; a month's SF2 and a period's SF8 download as before. Pinned by the existing writer and controller tests (Attendance, Nutrition), which load the real template.
2. **The demo seeder.** `make fixture-load` + `make fixture-seed` still build both demo schools, and a second seed still refuses. Pinned by `DemoFixturesTest`; coverage of `src/DataFixtures` must reach 100% (Task 2 adds cases if the gate finds lines no test runs).
3. **Every page still renders without `templates/`.** Twig's default path must not break when the folder is gone. Pinned by `lint:twig`, `cache:clear` and the full functional suite.
4. **The browser suite passes 5/5, three full runs in a row**, without slowing any test down (no fixed sleeps).
5. **The built CSS is the same** after limiting Tailwind's scan: same `tw:` utilities in `public/build/assets/app-*.css` (same hash, or a diff that shows only ordering), and a shorter build.

## Rulings in this plan

- `SchoolFixturesTrait` stays at the tests root: it is not in a legacy folder and 94 tests import it.
- `tests/bootstrap.php` and `tests/object-manager.php` stay (PHPUnit and PHPStan load them) and come under phpcs.
- The Excel templates go next to their writers (Infrastructure), since nothing else reads them.
- `src/` keeps only the module folders, `src/DataFixtures` (dev tooling) and `src/Kernel.php` (owner, 2026-11-09).

---

### Task 1: Tests, fixtures and Excel templates into place

**Files:**
- Move: `tests/Controller/Admin/AdminTestCase.php` → `tests/Support/AdminTestCase.php` (`App\Tests\Support\AdminTestCase`); rewrite its 44 importers and the tests in its old namespace that used it without an import.
- Move: `tests/KernelTimezoneTest.php` → `tests/Core/Integration/KernelTimezoneTest.php` (`App\Tests\Core\Integration`).
- Move: `tests/Repository/EnrollmentRepositoryTest.php` → `tests/Learners/Integration/Infrastructure/EnrollmentRepositoryTest.php` (`App\Tests\Learners\Integration\Infrastructure`); fix its PHPStan finding (`SexAtBirth|null->label()`: assert not null first).
- Move: `templates/forms/sf2-template.xlsx` → `src/Attendance/Infrastructure/Sf2/sf2-template.xlsx`, `templates/forms/sf8-template.xlsx` → `src/Nutrition/Infrastructure/Sf8/sf8-template.xlsx`; each writer's `TEMPLATE` becomes `__DIR__ . '/sf2-template.xlsx'` (`/sf8-…`), dropping the project-dir prefix if the writer then no longer needs it. Update the writers' docblocks.
- Delete: `templates/` (empty), `tests/Controller`, `tests/Repository`, `src/Entity`, `src/Repository` (only `.gitignore` files left).
- Modify: `phpunit.dist.xml` (drop the `legacy` suite and its comment), `docker/test/run-feature-tests.sh` (`--testsuite unit,integration,functional`), `src/Core/Presentation/assets/styles/tailwind.css` (drop `@source "../../../../../templates";`).

- [ ] **Step 1:** Move the tests and rewrite imports with a scratchpad script; check for repeated imports and classes in the old namespace that used `AdminTestCase` unimported.
- [ ] **Step 2:** Move the Excel templates and update the writers.
- [ ] **Step 3: Setup checks:** `cache:clear`, `lint:container`, `lint:twig src`, the route table identical to HEAD, and `debug:twig` showing no missing default path error.

### Task 2: Legacy layer, baselines, mappings and checker scopes

**Files:**
- `config/deptrac.yaml`: remove the `Legacy` layer, its ruleset entry and every `- Legacy` line in other rules; add the `DevFixtures` layer (`^App\\DataFixtures\\.*`) with the rules in Global Constraints; remove the `deptrac.baseline.yaml` import. Delete `config/deptrac.baseline.yaml`.
- `config/phpstan.neon`: remove the baseline include. Delete `config/phpstan-baseline.neon` once it is empty.
- `config/packages/doctrine.yaml`: remove the `App` mapping (`src/Entity`).
- `config/phpcs.xml`: the per-folder lists become `<file>../src</file>` and `<file>../tests</file>`.
- `phpunit.dist.xml`: `<source><include>` becomes `<directory>src</directory>`.
- Fix whatever the wider scopes find: phpcs in `tests/SchoolFixturesTrait.php`, `tests/Support/AdminTestCase.php`, `tests/bootstrap.php`, `tests/object-manager.php`, `src/Kernel.php`, moved tests; coverage of `src/Kernel.php` and `src/DataFixtures` (new cases in `tests/DataFixtures/Integration/DemoFixturesTest.php` for any line the gate reports, in that test's existing style).

- [ ] **Step 1:** Config edits with a scratchpad script; `make test deptrac` (0 violations, 0 uncovered; `DevFixtures` covers `DemoFixtures`).
- [ ] **Step 2:** phpcbf, then phpcs over everything; wrap what is left by hand.
- [ ] **Step 3:** PHPStan (cache cleared) over everything with no baseline: 0 errors.
- [ ] **Step 4:** Coverage of `src/DataFixtures` and `src/Kernel.php` from their tests alone; add the missing cases.

### Task 3: The browser test that fails at HEAD

`RoleSmokeTest::testPagesKeepTurboAndPrefetchSetting` signs in as the admin and lands on a student page (403 "doesn't have ROLE_STUDENT"); it passes alone and fails after `testStudentFlow`. The test log keeps only failing requests, so the cause is not yet known. Follow superpowers:systematic-debugging:

- [ ] **Step 1: Evidence.** Re-run the suite with every request of the Panther web server logged (for example a temporary test-only monolog handler at `debug` level, or the server's access log), and find the request that stores `/student` as the after-login page (`_security.main.target_path`) or otherwise sends the admin there.
- [ ] **Step 2: Fix the cause**, most likely in `tests/Browser/BrowserTestCase.php` (each test starting from a clean browser session) rather than in the app. If the cause is in the app (a user could hit it), stop and report it to the owner before changing behaviour.
- [ ] **Step 3:** Remove the temporary logging; the browser suite passes 5/5 three times in a row.

### Task 4: Tailwind's source scan

- [ ] **Step 1: Evidence:** time `pnpm build` in the web container and record the CSS file name and size.
- [ ] **Step 2:** In `src/Core/Presentation/assets/styles/tailwind.css` add `source(none)` to the utilities import (`@import "tailwindcss/utilities.css" layer(utilities) source(none);`), keeping the explicit `@source "../../../..";` (all of `src`). Tailwind 4.3.4 reads `source(none)` on that import and stops its automatic scan of the project root (which walked `vendor/` and `var/`).
- [ ] **Step 3:** Build again: same CSS (hash or a diff of the `tw:` rules), and a shorter build.

### Task 5: Docs, comments, deprecations

- [ ] **Step 1: Docs.**
  - `CLAUDE.md`: the suites line (no `legacy`); remove the baseline lines and "After moving a test … left the legacy suite"; the Tests section mentions `tests/Support/AdminTestCase.php`; add that `src/DataFixtures` is dev tooling with its own deptrac layer; the SF2/SF8 template locations; the coverage line becomes "100% of `src`"; the Gotcha on Tailwind/test sessions is updated to say what still applies.
  - `AGENTS.md`: replace "Code not yet moved into a module is left out of `config/phpcs.xml` until it moves." with "phpcs covers all of `src` and `tests`."
  - `.claude/skills/run-tests/SKILL.md`: drop "legacy" from the database suites.
- [ ] **Step 2: Comment sweep and deprecation check** (`debug:container --deprecations`) over everything touched.

### Task 6: Verification

- [ ] **Step 1: Scoped runs**, one at a time: the moved tests, `tests/Attendance` and `tests/Nutrition` (templates), `tests/DataFixtures`, `tests/Core`.
- [ ] **Step 2: Guard break:** point the SF2 writer at the old path and watch its test fail; restore.
- [ ] **Step 3: Full runs on the final code, one at a time:** `make test all`, `make test-feature` (100.00% of `src`), `make regression-test` (5/5). Schema valid, diff empty, routes identical.
- [ ] **Step 4: Fresh whole-change review** (most capable model, read-only); fix Critical/Important and note breaks with a failing test first; full runs again.
- [ ] **Step 5: Headless live check**, HEAD against the branch: the SF2 and SF8 downloads (size, type, file name), one page per role, and `make fixture-load` + `make fixture-seed` on a throwaway copy of the dev data giving the same counts on both sides; restore the dev data.
- [ ] **Step 6: Report** and propose the commit (code and this plan). Commit only after approval:

```
Remove the last legacy code and its tooling

Move the last tests and the SF2/SF8 Excel templates into their modules,
keep the demo fixtures as dev tooling with their own layer, and remove
the legacy layer, both baselines, the legacy test suite and the old
entity mapping. phpcs and the coverage gate now cover all code. Fix the
browser test that failed after the student flow, and stop Tailwind
scanning the whole project during the asset build.
```
