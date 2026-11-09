# ZIM School: modular architecture design

Date: 2026-11-06. Status: approved by the owner on 2026-11-06.

## 1. Why

ZIM School is becoming a product sold in packages to schools in the Philippines: first single schools in
Region VII, then Division and Region offices, then nationwide. It must make the daily work of teachers,
principals and other school staff faster, and be used every day.

The current code (Symfony 8.1, PHP 8.4, Doctrine, Twig, ~310 PHP files, 1,200 tests) is organised by Symfony
technical folders (`Controller/`, `Entity/`, `Repository/`, `Form/`, `Service/`) plus a few feature folders. It
won't carry many more features, roles, packages, translations and offline use without clear boundaries.

**This design moves all existing code into the planned architecture.** It changes structure, not behaviour:
every page, URL, route name, form, PDF and spreadsheet works exactly as today. Feature changes are decided after
the architecture is in place (section 12).

## 2. Goals, non-goals, anti-goals

Goals
- Every existing feature lives in a module with DDD layers; no code is left in the old technical folders.
- One structure every future feature follows, enforced by tools (deptrac, PHPStan), not by memory.
- The same engineering standards as ams-core-02 (the owner's Laravel project): DDD layers, everything through a
  bus, a uniform `Result`, strict static analysis, a 100% coverage gate, browser regression tests, JS tests.
- Realistic development data from a seeder, and phpMyAdmin in Docker so XAMPP isn't needed to look at the data.

Non-goals (in this design)
- New features or changed behaviour: tenancy filter, multiple roles, Division/Region access, packages, translations,
  offline/PWA, notifications, AI add-on. These come after, each with its own design (section 12).
- Rewriting screens in Vue or Tailwind. The toolchain is installed; screens stay as they are.
- Microservices, separate deployments or databases.

Anti-goals (would make this a failure even if it "works")
- Anything a teacher, principal or admin would notice as different.
- Lost or changed data. Table and column names stay the same unless a change is unavoidable and migrated.
- A `Core` that becomes a junk drawer of business logic.
- Boilerplate so heavy that a simple screen takes a day.

## 3. Decisions taken by the owner

- **Modular monolith**: one app, one deploy, one database, code split by business module.
- **DDD layers inside every module**: `Domain/`, `Application/`, `Infrastructure/`, `Presentation/`.
- **Shared code lives in `src/Core/`** (one folder, same four layers).
- **Everything goes through the bus**, as in ams-core-02, including simple screens.
- **Same testing standards as ams-core-02.**
- **Code style: phpcs PSR-12** (as ams-core-02; `AGENTS.md`'s php-cs-fixer line is updated).
- **Tooling runs in Docker only.** XAMPP/Laragon is not supported for tests, coverage or builds.
- **Node is allowed.**
- **Not live yet**: no production data, so namespaces and schema can change without backfills or session care.
- **Development seeder** with realistic test data.
- **One branch for all of this work** (section 11). Other feature work is paused until it is merged.

## 4. Modules

Modules depend on each other in one direction only (no cycles). The order below comes from the real Doctrine
relations between today's entities. A module may use modules **above** it, never below.

| # | Module | Entities moved in | Other code moved in |
|---|---|---|---|
| 0 | `Core` | `ActivityLog` | bus, `Result`, middleware, base exceptions, clock (`SchoolClock`), image processing, PDF/XLSX/storage adapters, shared Twig layout pieces |
| 1 | `Access` | `User` | login, password change, `UserChecker`, `ForcePasswordChangeListener`, `app:user:create`, profile basics |
| 2 | `Billing` | `Plan` | `PlanLimits`, `PlanLimitException`, plan admin |
| 3 | `Schools` | `School`, `Division`, `SchoolYear`, `GradingPeriod`, `SchoolAssignment`, `SchoolType`, `SchoolDayOff` | school/division/school-year admin, `SchoolVoter`, school profile, calendar |
| 4 | `Classes` | `Section`, `Subject`, `ClassPeriod`, `TeachingLoad`, `ScheduleEntry`, `Weekday`, `GradeLevel`, `ShsTrack` | section/subject/period/timetable/teacher-load screens |
| 5 | `Learners` | `Learner`, `Enrollment`, `TransferRequest`, `TransferStatus`, `Sf1Remark`, `LearningModality`, `SexAtBirth` | learner/transfer screens, `Sf1/`, `LearnerVoter`, learner IDs (`LearnerId/`) |
| 6 | `Attendance` | `RollCall`, `AttendanceMark`, `LearnerMark`, `Sf2Submission` | `Attendance/`, attendance screens, `AttendanceVoter` |
| 6 | `Grading` | `Grade`, `GradeChangeRequest`, `GradeChangeLine`, `GradeChangeStatus`, `GradeLock`, `GradeOpening`, `GradeUnlock`, `GradingSystem`, `CardSlipComment` | `Grading/`, `CardSlip/`, grade/grade-change/card-slip screens, `GradeVoter`, `CardSlipVoter` |
| 6 | `Nutrition` | `NutritionAssessment`, `NutritionMeasurement`, `Sf8Submission` | `Nutrition/`, SF8 screens, `NutritionVoter` |
| 6 | `LessonPlans` | `LessonPlan` | `LessonPlan/`, lesson plan screens, `LessonPlanVoter` |
| 6 | `Staff` | `PersonalDataSheet`, `Pds*`, `Citizenship`, `CivilStatus`, `DualCitizenshipBy`, `EducationLevel`, `ServiceRecord`, `ServiceRecordEntry`, `StaffIdDetails` | profile/PDS (`PdsPdfRenderer`), `ServiceRecord/`, `StaffId/`, `ServiceRecordVoter` |
| 6 | `Announcements` | `Announcement` | announcement screens |
| 7 | `Dashboards` | none | home, admin/principal/teacher/student dashboards (read-only screens that combine several modules) |

Modules on the same level (6) do not use each other. Where one reads another today (card slips read attendance
totals; dashboards read everything), it goes through the other module's public API (section 5). The exact home
of each remaining service, form, validator and template is decided per module in its plan; the table above is
the default.

Why a `Schools` module instead of putting `School`/`SchoolYear` in `Core` (my call): almost everything points at
them, but they are business entities with their own screens and rules. Keeping `Core` technical-only stops it
becoming the junk drawer the owner wants to avoid.

## 5. Layers and dependency rules

Inside a module, the ams-core-02 direction: `Presentation → Application → Domain ← Infrastructure`.

- **Domain**: entities, enums, value objects, domain events, repository interfaces, domain exceptions, domain
  services. Plain PHP. The only framework code allowed is Doctrine's mapping attributes (`Doctrine\ORM\Mapping`) and
  `Doctrine\Common\Collections` (my recommendation: Doctrine is a data mapper, so entities never talk to the
  database themselves; one file per entity; matches `AGENTS.md`'s "use PHP attributes"). Validation constraints on
  entities move to the Command DTOs (section 6); `UniqueEntity` checks become repository checks in handlers.
- **Application**: UseCases (writes), Queries (reads), their handlers, transformers, application services, and
  the module's public API (`Application/Api/`).
- **Infrastructure**: Doctrine repositories, PDF/XLSX writers, file storage, Messenger wiring, console commands'
  plumbing.
- **Presentation**: controllers, Symfony Forms, Twig templates (registered as a Twig namespace per module, e.g.
  `@Attendance/...`), Stimulus/Vue assets, console commands, voters.

Between modules
- A module's **Domain** may reference the Domain entities and enums of modules above it (Doctrine associations
  such as `RollCall → Section`). This keeps today's relations and queries working (my call: converting every
  relation to IDs would change behaviour and is not part of this design).
- Anything else a module needs from another module goes through that module's **public API**
  (`<Module>/Application/Api/`) or its **events**. Never another module's repositories, handlers or services.
- Inverse collections (`OneToMany`) only within the same module.
- `Core` uses no module.

Enforcement: `config/deptrac.yaml` with one layer per module-layer pair and the rules above. During the move a
`Legacy` layer covers code still in the old folders; `Legacy` may use modules, modules may not use `Legacy`; a
deptrac baseline lists today's violations and may only shrink. When the last module has moved, the `Legacy` layer
and the baseline are deleted.

## 6. Bus, messages and Result (mirrors ams-core-02's real code)

Names follow ams-core-02's actual classes:

- `Core\Application\Bus\CommandBusInterface` with `handle(UseCase|Query $command): Result`.
- `UseCaseBusInterface` and `QueryBusInterface` extend it.
- Marker interfaces `UseCase` and `Query`, both extending `Loggable` (`toLogArray(): array`).
- `UseCaseHandlerInterface::__invoke(UseCase $command): Result` and
  `QueryHandlerInterface::__invoke(Query $command): Result`, narrowed per handler with PHPStan generics
  (`@implements UseCaseHandlerInterface<AddXCommand>`, `@param AddXCommand $command`).
- Attributes `#[AsUseCaseHandler(XCommand::class)]` and `#[AsQueryHandler(XCommand::class)]`.
- Message classes are named `*Command` for both writes and reads: `Application/UseCase/Command/RecordRollCallCommand`,
  `Application/Query/Command/GetSectionTodayCommand`; handlers in `Application/UseCase/Handler/` and
  `Application/Query/Handler/`.
- `Result`: `success(mixed $data)`, `failure(\Throwable $e, string $errorCode)`, `isSuccess()`, `isFailure()`,
  `data()`, `exception()`, `errorCode()`.
- Domain exceptions extend `Core\Domain\Exception\DomainException` and carry an `errorCode()`.

Built on **Symfony Messenger** instead of a hand-written bus (my call): buses `usecase.bus`, `query.bus`,
`event.bus`; our attributes are autoconfigured onto Messenger handler tags for the right bus, so there is no custom
handler locator to cache or clear. Middleware, in order: logging (`toLogArray()`), exception handling → `Result`,
Doctrine transaction (`usecase.bus` only), validation (`#[Assert\...]` on Command DTOs), caching for Queries
marked `#[Cacheable]` (`query.bus` only; added in Phase 1 but used only where a module already caches).

Rules carried over from ams-core-02's checklist:
- Controllers make one bus call per action and build the response from `$result->data()`.
- Handlers never call other handlers; shared logic goes to a repository method or a service.
- Shaping data for a `Result` lives in static `{Entity}Transformer` classes under `Application/Transformer/`.
- Only messages routed to the async transport run in the background (today: whatever already does).

Forms: Symfony Forms in Presentation bind to Command DTOs, not entities. Forms that today edit an entity directly
get a DTO with the same fields, prefilled by a Query. Field names stay the same so templates and tests still work.

## 7. Frontend toolchain

- **Vite** (`pentatrion/vite-bundle`, confirm the current version in the plan) replaces AssetMapper/importmap.
  Existing Stimulus controllers and plain CSS move into the Vite build unchanged; Turbo and Stimulus stay; the
  `turbo-prefetch=false` setting stays.
- **Vue 3 (Options API only)**, **Tailwind 4**, **pnpm** are installed and wired, ready for later screens. Tailwind's
  base reset (preflight) is off or scoped so no current page changes. A small Stimulus controller can mount a Vue
  component and unmount it on Turbo navigation.
- ESLint, Stylelint, Vitest + Vue Test Utils + jsdom; existing Stimulus controllers get Vitest tests (needed for the
  JS coverage gate).
- The production build is plain files in `public/build/`; how deploys get them is decided before the first deploy.

## 8. Testing and quality standards

Matching ams-core-02:

- Tests mirror modules: `tests/<Module>/Unit/` (pure PHP, no kernel, no DB), `tests/<Module>/Integration/`
  (`KernelTestCase`: handlers, repositories, test DB), `tests/<Module>/Functional/` (`WebTestCase`: HTTP), and
  `tests/Browser/` (Symfony Panther, headless Chrome) for regression flows across modules.
- PHPUnit suites `unit`, `integration`, `functional`, `browser`, runnable separately and together.
- Existing tests move with their code and keep passing unchanged in what they assert: they are the proof that
  behaviour didn't change. New tests use the `// Initialize`, `// Execute`, `// Assert` (`// Expect` first)
  sections and the existing helpers (`SchoolFixturesTrait`, `AdminTestCase`) or their moved versions.
- Static checks: PHPStan level 9, deptrac, phpcs PSR-12, `composer audit`, ESLint, Stylelint. Legacy code gets
  baselines in Phase 1; each module leaves the baselines when it moves.
- Coverage gate: 100% PHP and JS for `Core` and every moved module (legacy excluded until it moves; at the end
  everything is covered).
- `phpunit.dist.xml` keeps failing on deprecations, notices and warnings.
- Browser regression (Panther): one smoke flow per role in Phase 1 (log in, open the dashboard, open one main
  screen), extended by each module with its main flow.
- Docker base image gains pcov, Node 24 + pnpm (Corepack) and Chrome, as ams-core-02's `Dockerfile.base`.
  `make` test targets use the Base Makefile's `tests:` config in `make-config.yaml`. `CLAUDE.md` and the
  `run-tests` skill are updated (Docker only).

## 9. Development data and tools

- **Seeder**: `doctrine/doctrine-fixtures-bundle` and `fakerphp/faker` (dev only, through Flex). A `demo` fixture
  group: a Region VII division; two schools on different plans; the current school year with grading periods; per
  school a principal, several teachers and students with known logins; sections, subjects, class periods,
  timetables, teaching loads, enrollments; a few weeks of attendance; some grades; a lesson plan; within plan seat
  limits. Loaded with the Base Makefile's targets: `make fixture-load` (drop, create, migrate; the migrations seed
  the plans and the `zim` admin) then `make fixture-seed` (`doctrine:fixtures:load --group=demo --append`).
  Logins and the shared dev password go in `CLAUDE.md`. Fixtures move into each module as it moves.
- **phpMyAdmin**: `phpmyadmin` service in `docker-compose.yml`, `http://docker.vernie.com:8096`, bound to this
  machine only, logs in as root. (Already added on 2026-11-06, documented in `AGENTS.md`.)

## 10. Database

- Entities keep their table and column names (explicit `#[ORM\Table]` names where the default would change after a
  move). Moving a class must produce an empty `doctrine:migrations:diff`.
- Doctrine mappings are configured per module (one mapping per `<Module>/Domain` folder).
- Existing migrations stay in `migrations/`; new ones keep using `/new-migration` with MySQL syntax.

## 11. Branch and order of work

- **Branch**: `feature/modular-architecture`, created from `develop` (owner's choice) with the project's git-flow
  convention, and holding all of this work. Merged back into `develop` only when every phase is done and all checks
  pass; `main` gets it at the next release. Other feature work waits until then (owner).
- **Phases**, each with its own plan and its own approved commit at the end:
  1. **Foundation**: Docker image (pcov, Node, Chrome); PHPStan, deptrac, phpcs, coverage and suites with baselines;
     `Core` (bus, `Result`, middleware, exceptions); Vite/Vue/Tailwind toolchain replacing AssetMapper; seeder;
     Panther smoke flows. No module moves yet.
  2. **Access, Billing, Schools**: the entities everything depends on.
  3. **Classes, Learners**.
  4. **Attendance, Grading, Nutrition, LessonPlans, Staff, Announcements** (one plan each, in that order).
  5. **Dashboards and clean-up**: dashboards module, delete the old folders, the `Legacy` deptrac layer and all
     baselines; update `CLAUDE.md`/`AGENTS.md`; full check run; merge to `develop`.
- After each module move: all four PHPUnit suites, PHPStan, deptrac, phpcs and Vitest pass, coverage is 100% for
  moved code, and `doctrine:schema:validate` is in sync.

## 12. After the architecture (separate designs, not in scope)

Kept here so the structure leaves room for them:
- **Tenancy filter**: a `school_id` on school-owned tables and a Doctrine filter for the current school
  (teachers/principals via `SchoolAssignment`, students via their active `Enrollment`; `Learner` is kept for life
  across schools; `TransferRequest` is seen by both schools).
- **Offline/PWA** for attendance, grade entry, class lists and lesson plans: save on the phone, sync with idempotent
  UseCases, data-privacy limits (RA 10173).
- Multiple roles and permissions, Division/Region access, package features, translations (English and Filipino),
  notifications, the AI add-on.

## 13. Left to the builder

- Exact deptrac layer config and baseline mechanics; how the handler attributes register onto Messenger.
- Where each remaining form, validator, Twig extension and template lands inside its module.
- Vue mount controller details, Vite entry layout, Tailwind scoping technique.
- Makefile target names (follow Base Makefile conventions); JS test folder.
- Seeder amounts.
- Keeping `CLAUDE.md` / `AGENTS.md` / skills up to date with every new command, convention and gotcha.

Alternative recorded but not chosen: Doctrine XML mapping in Infrastructure (100% pure Domain, two files per
entity, against `AGENTS.md`'s attribute rule).
