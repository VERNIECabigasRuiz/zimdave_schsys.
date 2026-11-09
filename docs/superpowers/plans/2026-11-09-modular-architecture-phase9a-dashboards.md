# Modular architecture, Phase 9a (Dashboards) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the home page, the four dashboards, the principal's shortcuts and the "now" cards into a `Dashboards` module that reads every other module only through their public APIs and the Core bus. Move the four role layouts into Core, with their menu answers behind a Core hook. Nothing a user would notice changes.

**Architecture:**
- `Dashboards` is the top module (spec section 4, row 7). It has no entities, so it has no Domain layer.
  - `Application/Query`: one query per page (handlers read the modules' `Application/Api/` and Core).
  - `Infrastructure/Core/RoleMenu`: implements the Core menu hook.
  - `Presentation`: controllers and templates (`@Dashboards`).
- **Layouts.** `templates/{admin,principal,teacher,student}/_layout.html.twig` move to `src/Core/Presentation/templates/<role>/_layout.html.twig` (`@Core/<role>/_layout.html.twig`). Spec section 4 puts shared Twig layout pieces in Core.
  - Their three menu functions keep their Twig names (`has_teaching_load`, `has_id`, `advises_section`). They move from the legacy `App\Twig\AppExtension` to a Core Twig extension that asks the hook `Core/Domain/Service/Menu/RoleMenuInterface` (owner, 2026-11-09).
  - The hook takes account ids, because Core may not name `User`.
- **New API methods.** The data the dashboards read today straight from repositories and services becomes API methods on the owning modules. Each method wraps today's call, so the numbers stay identical.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Twig, PHPUnit 13, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 rows 0 and 7, sections 5, 6, 8, 11).
- **Owner decisions (2026-11-09):**
  - Phase 9 is split into 9a (this plan) and 9b (clean-up: fixtures, legacy test folders, SF2/SF8 Excel templates, Legacy layer, baselines, empty folders, the legacy mapping, docs; plus fixing the browser test and Tailwind's scan).
  - The layouts go to Core behind a menu hook.
  - The owner merges into `develop` after 9b.
- **Scope:** this ticket only migrates. Regressions the move causes are fixed; existing flaws are left.
- The plan is committed with the phase.

**Branch:** `feature/modular-architecture` (8 committed as `3a9bea9`, notes as `7642889`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 0. Planning | 2026-11-09 16:46:21 | 16:54:01 | 7m 40s |
| 1. Module APIs for the dashboards | 16:54:01 | 17:04:25 | 10m 24s |
| 2. Core layouts and the menu hook | 17:04:25 | 17:11:14 | 6m 49s (together with Task 3) |
| 3. Dashboards module; legacy removed | 17:04:25 | 17:11:14 | 6m 49s (together with Task 2) |
| 4. Baselines, docs, comments, deprecations | 17:11:14 | 17:25:48 | 14m 34s |
| 5. Verification | 17:25:48 | 19:19:04 | 1h 53m 16s |

## Global Constraints

**Structure only.** These stay exactly as today:
- **Routes:** names, paths, methods and requirements. `app_home` `/`; `admin_dashboard` `/admin`; `principal_dashboard` `/principal`; `principal_now` `/principal/now`; the shortcuts `principal_{teachers,sections,learners,attendance,nutrition,grades,subjects,class_periods,service_record}`; `teacher_dashboard` `/teacher`; `teacher_now` `/teacher/now`; `student_dashboard` `/student`.
- **Access:** the class-level `#[IsGranted]` roles and route prefixes.
- **Redirects:** home sends each role to its dashboard (admin first, then principal, teacher, student) and renders the welcome page for anyone else. Each shortcut redirects to the same school route.
- **Unassigned principals:**
  - The dashboard and the shortcuts render the "not assigned" page.
  - `/principal/now` renders the empty card.
  - `/principal/service-record` refuses with 403 "You are not assigned to a school this school year."
- **Numbers:** every count and flag the pages show today, with the same conditions:
  - `plans_expiring` uses today + 30 days on Symfony's clock;
  - `year_stats` is null without a current year;
  - `can_create_section`, `can_manage_subjects`, `show_now`, `transfers_waiting` and `attendance_today` keep the teacher's rules;
  - the student's term is the first term unless `?term=` names one of the section's terms. `?term=` is read only when the student has a section, and then a non-number fails as it does today;
  - `failing` is 0 without a grading period.
- **Menus:** the same answers as today.
  - `has_teaching_load` is false only when the teacher's school this year is on a plan without a principal. It is true with no current year or no school.
  - `has_id` is true for a teacher or principal assignment this year.
  - `advises_section` is true when the teacher advises a section this year.
- **Pages:** the layouts' and dashboards' HTML is unchanged apart from the template paths. The route table matches HEAD, and `doctrine:migrations:diff` stays empty.

**Layers.**
- Dashboards may use Core (Domain, Application, BusAttributes, Presentation) and every module's Domain and Api. Never another module's repositories, Application services or handlers.
- Core uses no module. The new hook and Twig extension name no module class.
- Modules don't use Dashboards.
- Legacy stays a layer until 9b, for `src/DataFixtures` only.

**Bus rules.**
- Controllers extend `BusController`, except `HomeController`, which only redirects by role and reads nothing.
- Each data page is one query through the `QueryBusInterface`. The admin dashboard also sends Core's `GetRecentActivityCommand(15)`.
- Queries carry the actor's id and everything else their result depends on (the admin page's `expiringBy` date is set by the controller from Symfony's clock).
- No query is `#[Cacheable]`.
- Handlers may return entities, as other modules' page queries do (`GetSchoolGradesHandler`), so the templates keep reading `school.name` and the like.

**New code.** `declare(strict_types=1)`, PHPStan level 9 with no baseline entries, phpcs PSR-12 (lines ≤ 120), 100% coverage of `src/Dashboards` and of every module touched.

**Tests.**
- Moved tests keep their assertions; only the way they reach a value may change.
- New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first), with every setup line under `// Initialize`. Execute runs the code under test. Compare ids, not entities.
- Use `AdminTestCase` and `SchoolFixturesTrait`; check the trait before naming a helper.
- Kernel tests go in Integration or Functional.
- After moving tests: check class names, namespaces and suites. `tests/Controller` keeps only `Admin/AdminTestCase.php` (moved in 9b).

**Moving classes and helper scripts.**
- After moving, grep the short and full old names (`App\Controller\`, `App\Twig\AppExtension`, the template paths) in `src`, `templates`, `tests` and `config`.
- Check for repeated and same-namespace imports, and compare moved string literals with the originals.
- Helper scripts go in the scratchpad, written with the Write tool, in LF, run with `python3`. Copy the helpers already used in Phase 8 (`compare_routes.py`, `routes.py`, `prune_baseline.py`, `uncovered.py`, `to_lf.py`, `review_package_8.sh`, the live-check scripts).
- No inline heredocs, no `<<`, no `sed` for text with backslashes, no `git add -N`. Use `git -C` for the worktree, never `cd` into it.
- Before the first code change: save the HEAD route dump and a copy of `config/phpstan-baseline.neon`.

**Running checks.**
- Tests are written per task and run once in Task 5, one command at a time, in Docker (`MSYS_NO_PATHCONV=1`). `TEST_TARGET` takes one class, method or path.
- Read each check's output; save long output to a scratchpad file.
- Clear `reports/phpstan/.cache` before a PHPStan run that counts. Run deptrac through `make test deptrac`.
- After fixing one checker's findings, rerun the others on the same files.
- Watch long runs directly. Log a task's end and the next task's start together, and compute durations with a command.
- The browser test `RoleSmokeTest::testPagesKeepTurboAndPrefetchSetting` already fails at HEAD. It is fixed in 9b. Here it must fail the same way and nothing else may fail.

**PHPStan baseline.** It may only shrink (the eight `Principal/DashboardController` entries and the moved tests' entries go). No entry may name a module folder.

**Live-browser check.** Headless, without asking (owner). Move the worktree `.superpowers/sdd/2026-11-07-modular-architecture-phase2d-schools/head` to `7642889`. Start servers without any removal in the same command. Reset the dev data before each side and restore it after.

**Comments and commit.** Comments short and plain, with no Claude/Anthropic mention. One commit at the end after approval, through the Base hook, including this plan (`git add -f`).

## Review Focus

1. **The menu on every module's pages.** Every module template and controller that names a layout now points at the moved ones (80 references in 60 files). A teacher on the Individual plan has no "Teaching load" link, a teacher on the Basic plan has it, and a teacher with no school this year still has it.
   - The menu answers must not throw for an account the hook can't find (a deleted account in a stale session). Then all three answers fall back to what today's code gives for "no school": `has_teaching_load` true, the other two false.
   - Pinned by `RoleMenuTest` (Task 2) and the moved `TeacherAreaTest` / `PrincipalAreaTest`.
2. **The student's term.**
   - `?term=9` (not a term) shows the first term.
   - `?term=abc` for a student with no section shows the "not enrolled" page, as today.
   - A senior-high section with `?term=2` shows the second semester.
   - Pinned by the moved `StudentDashboardTest`, plus `testAnUnreadableTermIsIgnoredWithoutASection` (added after the review found the move read `?term` before checking for a section).
3. **Unassigned principals.** The dashboard and each shortcut show the "not assigned" page, `/principal/now` shows the empty card, and the service-record shortcut answers 403 with today's message. Pinned by `PrincipalAreaTest`, plus new cases for `/principal/now` and one shortcut.
4. **The admin dashboard without a current school year.** It shows no year block, the other counts stay, and recent activity is newest first (15 entries). Pinned by `DashboardControllerTest`, plus a new no-current-year case.
5. **Teacher dashboard flags on each plan.**
   - Basic plan (with principal): no "new section" or "subjects" actions, the "now" card shown, transfers not counted.
   - Individual plan: the actions are shown when allowed, no "now" card, and the adviser's pending transfers are counted.
   - Pinned by `TeacherAreaTest` and `DashboardNowTest`.

## Rulings in this plan

- `HomeController` doesn't use the bus: it only redirects by role.
- The menu hook takes account ids. The layouts pass `app.user.id`, which renders the same HTML.
- `TimetableQuery` stays in Classes Application. The new `TimetableDirectoryInterface` returns its arrays unchanged, and their shapes are documented on the API.
- `AccountDirectoryInterface::accountCounts()` keys match the template's `stats` keys, so the template is unchanged.

---

### Task 1: Module APIs for the dashboards

**Files:**
- **Modify** (the interface and its implementation, next to the existing one; find the implementation with `grep -rl "implements <Interface>" src`):
  - `src/Access/Application/Api/AccountDirectoryInterface.php`;
  - `src/Schools/Application/Api/SchoolDirectoryInterface.php`;
  - `src/Classes/Application/Api/SectionDirectoryInterface.php`.
- **Create:**
  - `src/Classes/Application/Api/{TimetableDirectoryInterface,TimetableDirectory}.php`;
  - `src/Learners/Application/Api/{LearnerDirectoryInterface,LearnerDirectory}.php`;
  - `src/Grading/Application/Api/{GradingDirectoryInterface,GradingDirectory}.php`.
  - The `Grading_Api` deptrac layer already exists; check that its rules let the new class use Grading's Application services.
- **Test:** each module's existing API test (`tests/<Module>/Integration/Api/…Test.php`; create `TimetableDirectoryTest`, `LearnerDirectoryTest` and `GradingDirectoryTest` beside them).

**Interfaces (produces):**
- **`AccountDirectoryInterface::accountCounts(): array{accounts: int, principals: int, teachers: int, students: int, inactive: int}`.** It wraps `UserRepositoryInterface::countAll()`, `countByRole()` for the three roles, and `countInactive()`.
- **`SchoolDirectoryInterface`:**
  - `expiringPlansBy(\DateTimeImmutable $limitDate): int` wraps `SchoolRepositoryInterface::countExpiringBy()`.
  - `yearOverview(SchoolYear $schoolYear): array{schools: int, assigned: int, unassigned: int, without_principal: int}`:
    - `schools` = `countActive()`;
    - `assigned` = `countPrincipals()`;
    - `unassigned` = the count of `findAssignablePrincipals()`;
    - `without_principal` = `countActiveWithoutPrincipal()`.
  - `teacherCount(School $school, SchoolYear $schoolYear): int` and `studentCount(School $school, SchoolYear $schoolYear): int` wrap `PlanLimits`.
- **No new Classes method for the section refusal (ruling):** `SectionManager::refusal()` only returns `SchoolDirectoryInterface::blockedReason()`, so the teacher dashboard calls that.
- **`TimetableDirectoryInterface`** wraps `TimetableQuery`:
  - `termsFor(Section $section): list<int>`;
  - `sectionState(Section $section, int $term): array`;
  - `schoolNow(School $school, SchoolYear $schoolYear, \DateTimeImmutable $at): array` wraps `now()`;
  - `teacherNow(User $teacher, SchoolYear $schoolYear, \DateTimeImmutable $at): array` wraps `nowForTeacher()`.
- **`LearnerDirectoryInterface`:**
  - `learnerOf(User $account): ?Learner` wraps `findOneByUser`;
  - `currentEnrollment(Learner $learner, SchoolYear $schoolYear): ?Enrollment` wraps `findCurrent`;
  - `remarksText(Enrollment $enrollment): ?string` wraps `Sf1Remarks::textFor`;
  - `pendingTransfersOut(School $school, SchoolYear $schoolYear, ?Section $fromSection = null): int` wraps `countPendingOutgoing`.
- **`GradingDirectoryInterface`:**
  - `defaultPeriod(School $school, SchoolYear $schoolYear): ?GradingPeriod` wraps `SectionGradeGrid::defaultPeriod`;
  - `failingCount(School $school, SchoolYear $schoolYear, GradingPeriod $period): int` is the count of `FailingLearners::forSchool`;
  - `pendingChangeRequests(School $school, SchoolYear $schoolYear): int` wraps `countPendingForSchool`.

- [ ] **Step 1: Write the tests.** One integration case per method, against data built with `SchoolFixturesTrait` (and the module's existing helpers):
  - each count matches a known setup (for example two teachers and one inactive account give `teachers` 2 and `inactive` 1);
  - `termsFor` gives `[0]` for Grade 7 and `[1, 2]` for Grade 11;
  - `schoolNow` / `teacherNow` give `state` `no_periods` without class periods;
  - `learnerOf` / `currentEnrollment` / `remarksText` are found and null;
  - `pendingTransfersOut` counts with and without a section;
  - `defaultPeriod` is null without periods;
  - `failingCount` counts one learner below 75;
  - `pendingChangeRequests` counts one pending request.
- [ ] **Step 2: Implement** the methods and the three new API classes (autowired; one implementation each).
- [ ] **Step 3: Setup checks:** `cache:clear` and `lint:container`.

### Task 2: Core layouts and the menu hook

**Files:**
- **Create:**
  - `src/Core/Domain/Service/Menu/RoleMenuInterface.php` (`hasTeachingLoad(int $accountId): bool`, `hasId(int $accountId): bool`, `advisesSection(int $accountId): bool`);
  - `src/Core/Presentation/Twig/RoleMenuExtension.php` (`#[AsTwigFunction]` `has_teaching_load`, `has_id` and `advises_section`, each taking `int $accountId` and asking the hook);
  - `src/Dashboards/Infrastructure/Core/RoleMenu.php` (implements the hook through `AccountDirectoryInterface::findById`, `SchoolDirectoryInterface::currentYear/schoolOf/assignmentOf` and `SectionDirectoryInterface::advisoryOf`, with the legacy `AppExtension` rules; an unknown id gives true/false/false).
- **Move:** `templates/{admin,principal,teacher,student}/_layout.html.twig` → `src/Core/Presentation/templates/<role>/_layout.html.twig`. Calls become `has_teaching_load(app.user.id)` and the like.
- **Modify:** every `{% extends '<role>/_layout.html.twig' %}` → `{% extends '@Core/<role>/_layout.html.twig' %}`.
- **Delete:** `src/Twig/AppExtension.php` and the `src/Twig` folder (grep config for `App\Twig` first).
- **Config:**
  - `config/packages/twig.yaml` (Dashboards namespace);
  - `config/deptrac.yaml` (`Dashboards_{Application,Infrastructure,Presentation}` layers with the rules in Global Constraints; the `Legacy` regex excludes `Dashboards\\`);
  - `phpunit.dist.xml` (`src/Dashboards`);
  - `config/phpcs.xml` (`src/Dashboards`, `tests/Dashboards`).
- **Test:** `tests/Core/Unit/Presentation/RoleMenuExtensionTest.php` and `tests/Dashboards/Integration/Infrastructure/RoleMenuTest.php`.

- [ ] **Step 1: Write the tests.**
  - `RoleMenuExtensionTest` (stub hook): each function passes the id and returns the hook's answer.
  - `RoleMenuTest`:
    - `has_teaching_load`: true with no current year, true for a teacher with no school, true on Basic, false on Individual;
    - `has_id`: true for a teacher and for a principal this year, false for a student or admin;
    - `advises_section`: true for an adviser, false otherwise;
    - an unknown id gives true/false/false.
- [ ] **Step 2: Implement**, move the layouts and rewrite the extends with a scratchpad script. Count the rewritten references (80 in 60 files, Twig and PHP; controllers pass layout names too).
- [ ] **Step 3: Setup checks:** `cache:clear`, `lint:container`, `lint:twig templates src`.

### Task 3: Dashboards module; legacy removed

**Files:**
- **Create** in `src/Dashboards/Application/Query/{Command,Handler}/`:
  - `GetAdminDashboardCommand(\DateTimeImmutable $expiringBy)` → `['plans_expiring' => int, 'school_year' => ?SchoolYear, 'year_stats' => ?array, 'stats' => array]`.
  - `GetPrincipalDashboardCommand(int $actorId)` → `school` (null when not assigned), `school_year`, and, when assigned, `teachers`, `students`, `blocked_reason`, `transfers_waiting`, `attendance_today`, `grade_changes_waiting`, `grading_period` and `failing`.
  - `GetPrincipalNowCommand(int $actorId)` → `['now' => ?array]`.
  - `GetPrincipalSchoolCommand(int $actorId)` → `['schoolId' => ?int, 'school_year' => ?SchoolYear]`, for the shortcuts.
  - `GetTeacherDashboardCommand(int $actorId)` → today's teacher template variables.
  - `GetTeacherNowCommand(int $actorId)` → `['now' => ?array]`.
  - `GetStudentDashboardCommand(int $actorId, ?int $term)` → today's student template variables. A null `term` means the first term.
  - Each `toLogArray()` holds the ids and the term only.
- **Move** (as Dashboards Presentation controllers extending `BusController`, same routes and class attributes):
  - `src/Controller/HomeController.php` → `src/Dashboards/Presentation/Controller/HomeController.php` (stays `AbstractController`);
  - `src/Controller/Admin/DashboardController.php` → `AdminDashboardController`;
  - `src/Controller/Principal/DashboardController.php` → `PrincipalDashboardController`;
  - `src/Controller/TeacherController.php` → `TeacherDashboardController`;
  - `src/Controller/StudentController.php` → `StudentDashboardController`.
- **Move templates:** `templates/{home/index,admin/dashboard,principal/dashboard,principal/_now,teacher/dashboard,teacher/_now,student/dashboard}.html.twig` → `src/Dashboards/Presentation/templates/` (same subpaths, `@Dashboards/...`).
- **Move tests** to `tests/Dashboards/Functional/`: `tests/Controller/Admin/DashboardControllerTest.php` (as `AdminDashboardTest`), `tests/Controller/Principal/PrincipalAreaTest.php`, `tests/Controller/StudentDashboardTest.php`, `tests/Controller/TeacherAreaTest.php` and `tests/Controller/DashboardNowTest.php`.
- **Delete:** `src/Controller` (keep `.gitignore` only if the folder must stay; it doesn't), and the emptied `templates/{home,admin,principal,teacher,student}`.

- [ ] **Step 1: Move the tests and add the new cases:**
  - `testStudentAccountWithoutALearnerRecordIsNotEnrolled` and `testAnUnreadableTermIsIgnoredWithoutASection` (student);
  - `testUnassignedPrincipalGetsAnEmptyNowCard`, `testEveryShortcutGoesToTheOwnSchoolPage` and `testEveryShortcutShowsTheNotAssignedPage` (principal);
  - `HomeTest` (an account with no school role gets the welcome page);
  - the admin dashboard without a current year was already pinned (`testDashboardWithoutCurrentSchoolYear`).
  - `principal/not_assigned.html.twig` goes to Core (`@Core/principal/not_assigned.html.twig`), not Dashboards: Schools and LessonPlans render it too (ruling).
- [ ] **Step 2: Implement** the queries, handlers and controllers. Each handler reads the account through `AccountDirectoryInterface::findById` (a missing account is a `\LogicException`) and the current year through `SchoolDirectoryInterface::currentYear()`.
- [ ] **Step 3: Setup checks:** `cache:clear`, `lint:container`, `lint:twig`, and the route table identical to HEAD.

### Task 4: Baselines, docs, comments, deprecations

- [ ] **Step 1: Static checks.** phpcbf, then phpcs clean (LF line endings). PHPStan on the touched modules (cache cleared), then on everything. Prune the baseline and confirm it only shrank. `make test deptrac`: 0 violations and 0 uncovered.
- [ ] **Step 2: Docs.** In `CLAUDE.md`:
  - a `Dashboards` paragraph (home, the four dashboards, the principal shortcuts, the "now" cards; one query per page; reads only APIs; the menu hook implementation);
  - Core: the role layouts (`@Core/<role>/_layout.html.twig`) and `RoleMenuInterface`;
  - the API list: the new methods and the three new APIs;
  - the `TimetableQuery` sentence (now reached through `TimetableDirectoryInterface`);
  - the coverage list adds `src/Dashboards`.
- [ ] **Step 3: Comment sweep and deprecation check** (`debug:container --deprecations`) over everything touched.

### Task 5: Verification

- [ ] **Step 1: Scoped runs**, one at a time: `tests/Dashboards`, the new API tests, `tests/Core`, then the moved users of the layouts (`tests/Staff/Functional`, `tests/Classes/Functional`). Fix, then re-run only the failures.
- [ ] **Step 2: Coverage** of `src/Dashboards` and the touched API classes: nothing uncovered.
- [ ] **Step 3: Guard breaks.** Make `RoleMenu::hasTeachingLoad` return `true` always, and watch the Individual-plan case fail. Drop the `?term` check, and watch the `?term=9` case fail. Restore both.
- [ ] **Step 4: Full runs on the final code, one at a time:** `make test all`, then `make test-feature` (100.00%), then `make regression-test` (only the known HEAD failure). Schema valid, diff empty, routes identical.
- [ ] **Step 5: Fresh whole-change review** (most capable model, read-only). Fix Critical and Important findings, and anything that breaks a written note, with a failing test first; then the full runs again.
- [ ] **Step 6: Headless live check**, HEAD against the branch on the same dev data, then restore it:
  - `/` for each role;
  - the admin dashboard;
  - the principal dashboard, `/principal/now` and two shortcuts;
  - the teacher dashboard and `/teacher/now` for `demo.teacher1` (Basic) and `demo.solo` (Individual);
  - the student dashboard with `?term=` absent and `?term=2`;
  - one page per module for each role, to check the menus.
- [ ] **Step 7: Report** and propose the commit (code and this plan). Commit only after approval:

```
Move the dashboards into a Dashboards module

Move the home page, the admin, principal, teacher and student dashboards
and the principal's shortcuts into a Dashboards module that reads the
other modules only through their public APIs and the bus. The role
layouts move to Core, with their menu answers behind a Core hook that
Dashboards implements. Pages, messages and URLs are unchanged.
```
