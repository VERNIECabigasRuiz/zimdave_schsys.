# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

Team rules both developers follow live in `.claude/rules/team-notes.md` (loaded automatically). They apply to every change and commit, together with this file and `AGENTS.md`.

## What this is

ZIM School: a Symfony 8.1 / PHP 8.4 school grading platform (Doctrine ORM on MySQL, Twig, Stimulus + Turbo, plain CSS in `assets/styles/`). Assets are bundled by Vite (`pentatrion/vite-bundle`, output in `public/build/`); Vue 3 (Options API, mounted with `{{ vue_component() }}`) and Tailwind 4 (prefixed `tw:`) are available. Node 24 and pnpm run in Docker.

New code goes into `src/Core/` or a module folder with DDD layers (Domain, Application, Infrastructure, Presentation). Use cases and queries go through the Core bus: inject `UseCaseBusInterface` or `QueryBusInterface`, call `handle()`, and get a `Result`. Handlers are marked `#[AsUseCaseHandler(X::class)]` or `#[AsQueryHandler(X::class)]`. A use case handler reports failure by throwing a `DomainException` (its code becomes the `Result`'s error code), never by returning `Result::failure()`: only a throw rolls back its database writes. A query must carry everything its result depends on (user, school), since the cache key is built from the query alone; a query marked `#[Cacheable('namespace-{property}')]` is cached and invalidated by that tag. `config/deptrac.yaml` holds the layer rules.

Layout inside a layer (as ams-core-02): entities in `Domain/Model`, repository interfaces in `Domain/Model/Repository`, other interfaces in `Domain/Service/<Area>`, their implementations in `Infrastructure/<Area>`, Twig templates in `Presentation/templates` (a Twig namespace per module, e.g. `@Core/base.html.twig`). Core provides `ActivityLoggerInterface` (the "Recent activity" feed, saved on the caller's flush), `SchoolClockInterface` (now on the school's clock), `ImageProcessorInterface` (stores uploads as WebP; takes a file path) and `PdfRendererInterface` (HTML to PDF with Dompdf); depend on these interfaces, never on their implementations. Entities in a module's Domain don't name their repository class, so inject the repository interface; `getRepository()` returns a plain Doctrine repository without the module's methods.

Controllers that use the bus extend `Core\Presentation\Controller\BusController`: `domainFailure($result)` returns the `DomainException` to show (flash or form error) and throws any other failure, so it still ends in an error page. A handler that must keep others out until its changes are saved calls `TransactionLockInterface::acquire($key, $busyMessage)`: the lock is held until the use case's transaction ends (use the same keys as the legacy locks, e.g. `school-teachers-<id>`). Locks are taken only inside use case handlers (outside one, `acquire()` throws); taking a key the use case already holds does nothing. Take the lock before any read in the handler: the transaction's first read fixes what later reads see.

Modules so far: `Core`, `Access` (accounts, sign-in, password change, admin account screens, `app:user:create`, profile photo), `Billing` (plans and the admin plan screens; `Plan` is `App\Billing\Domain\Model\Plan`) and `Schools` (schools, divisions, school years and grading periods, principal/teacher assignments, days off and `SchoolCalendar`, `SchoolVoter`, `PlanLimits`; the admin school, division and school-year screens, principal assignment, the school's teachers, the admin's teacher-school screens, the principal's school profile and the calendar). `PrincipalAssigner` and `SchoolTeacherManager` are Schools Application services (the demo fixtures use them too); `PlanLimitException` and `AssignmentException` are in `App\Schools\Domain\Exception`. A double-submitted teacher form comes back from the bus as a `UniqueConstraintViolationException`; the teacher controllers show "This was already saved by another request…" for it. Their entities (`School`, `SchoolYear`, `GradingPeriod`, `SchoolAssignment`, `SchoolDayOff`, `Division`, and the `SchoolType`, `GradeLevel`, `GradingSystem` enums) are in `App\Schools\Domain\Model`; inject the repository interfaces from `Schools/Domain/Model/Repository/` (`findById()`, not Doctrine's `find()`). The teacher PDS download (`school_teacher_pds`) stays legacy until Staff moves.

`Classes` holds sections, subjects, class periods, teaching loads and timetable entries (`Section`, `Subject`, `ClassPeriod`, `TeachingLoad`, `ScheduleEntry`, and the `Weekday`, `ShsTrack` enums in `App\Classes\Domain\Model`; repository interfaces in `Classes/Domain/Model/Repository/`) with the section, subject and class period screens, the section timetable (`school_timetable`, its JSON calls and the `timetable` Stimulus controller), the teacher load pages (`school_teacher_load`, `teacher_load`), the Individual-plan teacher's own section (`teacher_section_new`) and subjects (`teacher_subjects*`). `SectionManager`, `SubjectManager`, `ClassPeriodManager` and `TimetableManager` are Classes Application services that check an edit before applying it (they don't flush, so callers outside the bus such as the fixtures flush). Class period and timetable changes take the `school-timetable-<id>` lock first. `TimetableQuery` (Classes) is the timetable's read side; the legacy principal, teacher and student dashboards use it too.

`Learners` holds learners, enrollments and transfer requests (`Learner`, `Enrollment`, `TransferRequest`, and the `TransferStatus`, `Sf1Remark`, `LearningModality`, `SexAtBirth` enums in `App\Learners\Domain\Model`; repository interfaces in `Learners/Domain/Model/Repository/`; `Sf1Remarks` in `Learners/Domain/Service/Sf1/`) with the school's learner pages (`school_learners*`), the adviser's class pages (`teacher_class*`) and the admin learner search (`admin_learners`). `LearnerEnrollment` is a Learners Application service that checks a change before applying it (no lock, no flush; the fixtures flush). Its handlers take `learner-<id>` and then `learners-school-<id>` first, the keys the legacy `LearnerLock` also uses. Who may do what is `LearnerPolicy` (Application); handlers check it (a refusal is a 403) and `LearnerVoter` (`Learners/Presentation/Security/`) delegates to it. Still legacy until transfers, SF1 and learner IDs move: `TransferManager`, `LearnerLock`, `EnrollmentGuard`, `SchoolTransferController`, `EnrollSectionFormType` (the transfer request form), `src/Sf1/`, `src/LearnerId/` and the SF1/ID downloads in `LearnerDownloadController`; the adviser's grades page (`TeacherClassGradesController`) stays until Grading moves.

A module may use another module's Domain entities, but anything else only through that module's public API in `<Module>/Application/Api/` (its own deptrac layer `<Module>_Api`): Billing's `PlanCatalogInterface`, Access's `AccountDirectoryInterface` (find by id or username, register an account, set a temporary password, username taken), Schools' `SchoolDirectoryInterface` (find a school, the current and previous school year, a user's school, a school's teachers (all assigned, or only accounts still teachers), why a school's plan blocks changes, whether it may enroll another learner or a section is full, all schools, and the `MANAGE` voter attribute), Classes' `SectionDirectoryInterface` (find a section, a school's sections, a teacher's advisory). Data a lower module owns reaches an upper one through a hook interface in the upper module's `Domain/Service/`, implemented for now by legacy classes: Schools' `GradingPeriodUsageInterface` and `DayOffAttendanceInterface` in `src/Service/SchoolsHooks/`, Classes' `SubjectRecordsInterface` in `src/Service/ClassesHooks/`, and Learners' `LearnerGradesInterface` in `src/Service/LearnersHooks/`. Classes implements Schools' `TeacherDepartureInterface` in `Classes/Infrastructure/Schools/`. Learners implements Schools' `EnrolledLearnersInterface`, Classes' `SectionLearnersInterface` and Access's learner link and role-change hooks in `Learners/Infrastructure/{Schools,Classes,Access}/`. Schools implements Billing's `PlanUsageInterface` and Access's school-name, principal role and teacher-seat hooks in `Schools/Infrastructure/{Billing,Access}/`. `User` is `App\Access\Domain\Model\User`; it is the only Domain class allowed to implement Symfony's `UserInterface` and `PasswordAuthenticatedUserInterface` (deptrac layer `SecurityUserContract`). Rules from modules below Access reach it through hooks in `Access/Domain/Service/Account/` (role change, reactivation, learner link, school names).

## Two ways to run it (both are in use)

If `docker ps` shows `zimdave_schsys-web-1` running, use Docker; otherwise assume the local setup.

- **Docker** (via the shared Base Makefile at `P:/ProgramData/Projects/Base`): see the Docker section in `AGENTS.md`. Run console/phpunit inside the container: `make bash-exec CMD='php bin/console ...'`. Never use `make run`/`make stop` when other projects' stacks are stopped; they prune all stopped containers machine-wide.
- **Local XAMPP/Laragon** (vhost on `http://localhost:8081/`): runs the app only; tests, coverage, static checks and asset builds are Docker-only (build assets in Docker once so `public/build/` exists). Needs PHP **8.4** (Laragon here ships 8.3) with intl, gd, exif, zip, mbstring, pdo_mysql. `.env` still holds Symfony's default PostgreSQL `DATABASE_URL`; put a MySQL one in `.env.local` (git-ignored). Create the test DB too: `php bin/console doctrine:database:create --env=test` then `doctrine:migrations:migrate -n --env=test` (the test DB name gets a `_test` suffix).

The root `.htaccess` denies everything on purpose: only `public/` may be web-served.

Browser checks: Claude in Chrome needs the Chrome extension installed and enabled with `/chrome` (or `claude --chrome`); otherwise use the `playwright` plugin from `.claude/settings.json`. A live check served with `php -S` needs a router script that serves existing files (`public/build`); without one the pages run without their JavaScript.

## Domain terms

- **PDS** = Personal Data Sheet (CSC Form 212), edited under `/profile`, printed to PDF by `PdsPdfRenderer` (dompdf).
- **Plans/seats**: a `School` has a `Plan` (Billing) and a seat count; `PlanLimits` (Schools) enforces teacher/student caps (`PlanLimitException`); its student checks take a section's id and label. Migrations seed the `Basic` and `Individual` plans; tests rely on them.
- **School years** are shared by all schools, exactly one is `current` (`SchoolYearRepositoryInterface::findCurrent()`). Principal/teacher membership is per year via `SchoolAssignment`.
- Each `User` has exactly one role (constants on `User`, read with `getRole()`). `SchoolVoter::MANAGE` lets admins manage any school and principals only their school in the current year.
- A migration seeds the default admin user `zim` on a fresh DB. Create other users with `php bin/console app:user:create <username> <tempPassword> <fullName> --role=admin|principal|teacher|student`.

## Gotchas

- `config/reference.php` is regenerated by Symfony; never edit it.
- PHPStan's Doctrine rules allow a nullable property on a NOT NULL column (`allowNullablePropertyForRequiredField`), because entities are built empty and filled by setters; check such columns yourself.
- `@Core/base.html.twig` (`src/Core/Presentation/templates/`) sets `turbo-prefetch=false` deliberately so one-time passwords shown on a page aren't consumed by prefetch.
- Uploaded photos/logos are personal data: stored under `public/uploads/` (git-ignored), or `var/test_public` in tests (`app.upload_root`). `ImageProcessorInterface` re-encodes everything to webp.
- Schema changes only through migrations with MySQL syntax (use `/new-migration`).
- Every Twig page needs the built assets (`public/build/.vite/entrypoints.json`); without them `vite_entry_*_tags()` throws. The web container builds them on start; rebuild with `make bash-exec CMD='pnpm build'`.
- `assets/controllers/csrf_protection_controller.js` isn't a Stimulus controller: `assets/bootstrap.js` imports it for its side effect (the CSRF cookie on submit). Keep it out of the controller list, or forms and login stop working.
- Stimulus controllers in a module's `Presentation/assets/controllers/` set `import.meta.stimulusIdentifier` so their names don't change when they move.
- Tailwind classes only with the prefix (`tw:flex`); it has no preflight, so it can't restyle existing pages.
- `@symfony/ux-turbo` and `@symfony/ux-vue` come from `vendor/` (`file:` in `package.json`, rewritten by Flex). Run `make bash-exec CMD='pnpm install'` after a composer change to them. pnpm settings live in `pnpm-workspace.yaml` (pnpm ignores `.npmrc`); `packageImportMethod: copy` is what makes `file:` packages install on the Windows bind mount.

## Demo data

`make fixture-load` (fresh database with the migrations' plans and `zim`), then `make fixture-seed`. It adds schools `999001` (Basic: principal, six teachers, three sections with timetables, learners, attendance, first grading period grades, a lesson plan) and `999002` (Individual: one teacher, one section). Sign in as `demo.principal`, `demo.teacher1` … `demo.teacher6` or `demo.solo`, password `demo1234`. A second `make fixture-seed` refuses; run `make fixture-load` first.

## Tests

- Use `/run-tests`. `phpunit.dist.xml` fails on any deprecation, notice or warning; fix those, don't silence them.
- Suites: `unit`, `integration`, `functional` (`tests/<Module>/Unit|Integration|Functional`), `browser` (`tests/Browser`, Panther) and `legacy` (today's test folders, emptied as modules move). JS tests live in `tests/<Module>/Javascript`.
- `make test <type>` runs static checks and unit tests in a throwaway container without a database (`composer-audit`, `phpstan`, `deptrac`, `phpcs`, `eslint`, `stylelint`, `phpunit`, `vitest`, or `all`). `make test-feature` runs the database suites in `web` and requires 100% coverage of the moved modules (`src/Core`, `src/Access`, `src/Billing`, `src/Schools`, `src/Classes`, `src/Learners`; each new module is added to `<source>` in `phpunit.dist.xml`). `make regression-test` runs the browser suite. Reports land in `reports/`.
- PHPStan runs at most 4 workers (`config/phpstan.neon`); one per CPU filled Docker's memory and got MySQL killed. Keep the cap. Clear its cache (`reports/phpstan/.cache`) before a run whose result you rely on; a run on a few folders can report stale results.
- PHPStan and deptrac use baselines (`config/phpstan-baseline.neon`, `config/deptrac.baseline.yaml`) for code from before the move; don't add new entries to them.
- Admin controller tests extend `tests/Controller/Admin/AdminTestCase.php` (logs in as `zim`, `createUser()`, `findUser()`, `activityActions()`). Build school data with `tests/SchoolFixturesTrait.php` (`schoolYear()`, `plan()`, `school()`, `assign()`, `plainUser()`); it wipes school data in `setUp` but keeps the seeded plans. No transaction rollback between tests.
- New tests group their body with `// Initialize`, `// Execute`, `// Assert` comments (`// Expect` first when an exception is expected). Existing tests don't; leave them as they are.
- Don't name test helpers after PHPUnit `TestCase` methods (`count`, `output`, `name`…); many are final.
- When moving validation off an entity, grep the tests for validator calls on it.
- In functional tests, reload an entity through its repository after a request before changing it; the object loaded before the request is detached, so the change isn't saved.
- Write test logs and coverage files to a temp folder outside the repo, not onto the Docker bind mount.

## Git

Git-flow: `feature/*` branches off `develop`, merged back into `develop`; `main` holds releases. Commit messages describe the change plainly, with no ticket/plan references.

Commits use the Base project's commit message hook, set once per clone: `git config core.hooksPath P:/ProgramData/Projects/Base/git/git-hooks`. Without it a global hooks path (another project's) may rewrite the subject line.
