# Modular architecture, Phase 2d (Schools: structure and admin) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the school entities, their repositories, `SchoolVoter`, the school calendar and the school, division, school-year and school-profile screens into a `Schools` module with DDD layers, every action through the Core bus, with nothing a user would notice changing.

**Architecture:** Same layout and patterns as `Billing` (Phase 2c). The module's data that lower modules own (grades, roll calls) reaches it through Schools hook interfaces implemented by legacy classes in `src/Service/SchoolsHooks/`. Plans reach it through Billing's first public API (`Billing/Application/Api`), which gets its own deptrac layer. The people screens (principal assignment, the school's teachers, `/admin/accounts/{id}/school`), `PlanLimits`, `SchoolTeacherManager`, `PrincipalAssigner` and the Access account API are Phase 2e and stay legacy here (imports rewritten only).

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Twig, PHPUnit 13, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row `Schools`, sections 5, 6, 8, 10). Owner decisions (2026-11-07): Schools is split into 2d (this plan) and 2e (people); `GradeLevel` and `GradingSystem` move into the Schools Domain; all of `PlanLimits` moves in 2e, with learner counts through a Schools hook.

**Branch:** `feature/modular-architecture` (Phase 2c committed as `a2e31a7`, notes as `446d12f`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 1. Schools domain, persistence and wiring | 19:09:06 | 19:24:45 | 16m |
| 2. School and division administration | 19:24:45 | 19:29:14 | 4m (coding began ~19:17, during Task 1's container start) |
| 3. School years | 19:29:14 | 19:35:38 | 6m |
| 4. School profile, calendar and voter | 19:35:38 | 19:42:02 | 6m |
| 5. Baselines, docs, comments, deprecations | 19:42:02 | 20:04:26 | 22m |
| 6. Verification | 20:04:26 | 21:25:16 | 81m (includes a MySQL restart, the review fix pass and the headless live check) |

## Global Constraints

- Structure only: URLs, route names, form field names (`school_form[...]`, `division_form[...]`, `school_year_form[...]`, `school_profile_form[...]`, `day_off_form[...]`), flash messages, error texts and where they show, activity-log lines and access rules stay as today. Table and column names stay; `doctrine:migrations:diff` stays empty; the route table matches HEAD.
- Schools may use Core, Access Domain, Billing Domain and Billing Api. Legacy may use Schools. Grades and roll calls reach Schools only through its hook interfaces.
- Schools Domain may use `Doctrine\ORM\Mapping`, `Doctrine\Common\Collections`, Core/Access/Billing Domain; nothing from Symfony. Mapping types as strings (no `Doctrine\DBAL\Types\Types`). Entities don't name a repository class; validation moves to the commands.
- Controllers extend `Core\Presentation\Controller\BusController` and show a `DomainException` as today's flash or form error (on the same field). Handlers refuse by throwing a Schools/Core `DomainException`. Forms bind to the bus Command objects. Templates move to `src/Schools/Presentation/templates` with their relative paths kept (`@Schools/admin/schools/index.html.twig`, `@Schools/attendance/calendar.html.twig`, …); template edits are limited to reading transformer keys where a template called an entity method.
- New code: `declare(strict_types=1)`, PHPStan level 9 with no baseline entries, phpcs PSR-12 (lines ≤ 120), 100% coverage of `src/Core`, `src/Access`, `src/Billing`, `src/Schools`.
- Moved tests keep their assertions. New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first) and the existing helpers (`AdminTestCase`, `SchoolFixturesTrait`). Test helpers never reuse PHPUnit `TestCase` method names.
- Edits with backslashes go through the file tools or scratchpad scripts, never inline `sed`. When wrapping a signature as `): Type {`, replace the old `{` line too.
- Tests are written per task and run once in Task 6, one command at a time, in Docker. `MSYS_NO_PATHCONV=1` for `docker exec` with container paths; deptrac with `--cache-file=reports/deptrac/.cache`.
- PHPStan baseline: delete `reports/phpstan/.cache`, prune entries for deleted files, regenerate; the total must not grow and no entry may name `src/Schools`, `tests/Schools`, `src/Billing`, `src/Access`, `src/Core`, their tests, or a file not on `develop`.
- Live-browser check: ask first and confirm headless or visible (default visible). Comments short and plain; no Claude/Anthropic mention. One commit at the end after approval, through the Base hook.

## Review Focus

1. **Switching a school year between terms and quarters.** Refused on `gradingSystem` with "Close grading in every school before switching between terms and quarters." while any school has a period open; refused with "<Term 2> has grades. Switching to quarters would delete them." when a removed period is graded; rows that don't match the new system are rebuilt evenly. Period date errors keep their paths (`gradingPeriods[1].startDate`). Pinned by the moved `SchoolYearControllerTest` and `SchoolYearHandlersTest` (Task 3).
2. **A plan without a principal.** Editing a school that has a principal this year onto such a plan shows "Remove the principal of <school> for <year> before choosing the <plan> plan." under `plan`, and nothing is saved. Pinned by the moved `SchoolControllerTest` and `SchoolHandlersTest::testUpdateRefusesAPlanWithoutPrincipalWhileOneIsAssigned` (Task 2).
3. **Region and division pairs.** A division outside the chosen region gets "Choose a division in the selected region."; an old typed pair not on the list stays selectable as "<value> (not on the list)" and saves unchanged. Pinned by the moved `SchoolControllerTest` (Task 2).
4. **Days off.** Reversed range, outside the school year, more than 62 days: today's messages as flashes; weekends and existing days skipped ("Those days are already off."); the warning counts sections that already took attendance; removing another school's day off is 404. Pinned by `DayOffHandlersTest` (moved from `AttendanceRecorderTest`) and `SchoolCalendarControllerTest` (Task 4).
5. **Uploads.** A bad logo or signature shows the image error under `logo` (profile) or `signature` (division) and saves nothing; a new ID signature records the principal who uploaded it, and the profile shows it only to that principal. Pinned by the moved `SchoolProfileTest`, `DivisionControllerTest` and new handler tests (Tasks 2 and 4).

## Rulings in this plan

- `GradeLevel` and `GradingSystem` move to `App\Schools\Domain\Model` (owner).
- `SchoolCalendar` (from `src/Attendance/`) becomes `App\Schools\Domain\Service\Calendar\SchoolCalendar`; `PhilippineDivisions` (from `src/Data/`) becomes `App\Schools\Domain\Model\PhilippineDivisions`.
- `AttendanceRecorder::addDaysOff()`/`removeDayOff()` and `MAX_RANGE_DAYS` move into the day-off handlers; the roll-call count comes through `DayOffAttendanceInterface`.
- The Access hooks that read only Schools data (`CurrentSchoolNames`, `PrincipalRoleChangeGuard`) and Billing's `CurrentPlanUsage` move to `src/Schools/Infrastructure/{Access,Billing}/` with their class names. `TeacherSeatReactivationGuard`, `LearnerAccountLink` and `LearnerRoleChangeGuard` stay legacy.
- The admin `SchoolController`'s principal actions (`principal`, `removePrincipal`, `copyPrincipals`) stay legacy in a renamed class `App\Controller\Admin\SchoolPrincipalController` (same routes) until 2e.
- The principal's school profile drops its `SchoolVoter` call: its query finds the school with the voter's own rule (principal of it in the current year), so the outcome is the same.
- A forged CSRF token on an unknown id gets the token refusal instead of a 404 (CSRF ids switch from `args["school"].getId()` to `args["id"]`, same token values); a valid token on a missing row still gets 404. Same as Billing.

---

### Task 1: Schools domain, persistence and wiring

**Files:**
- Move to `src/Schools/Domain/Model/` (`App\Schools\Domain\Model\…`, no `repositoryClass`, no `Assert`/`UniqueEntity`/`Types`/`ExecutionContextInterface`; same tables, columns, getters/setters and trimming): `School`, `SchoolType`, `Division`, `SchoolYear` (without `validateGradingPeriods()`), `GradingPeriod`, `SchoolAssignment`, `SchoolDayOff`, `GradeLevel`, `GradingSystem` from `src/Entity/`; `PhilippineDivisions` from `src/Data/`. `School::PROFILE` is deleted.
- Move: `src/Attendance/SchoolCalendar.php` → `src/Schools/Domain/Service/Calendar/SchoolCalendar.php`
- Create: `src/Schools/Domain/Model/Repository/{School,SchoolYear,SchoolAssignment,Division,SchoolDayOff}RepositoryInterface.php`, `src/Schools/Infrastructure/Repository/Doctrine{School,SchoolYear,SchoolAssignment,Division,SchoolDayOff}Repository.php` (bodies moved from `src/Repository/`, which are deleted), `src/Billing/Application/Api/{PlanCatalogInterface,PlanCatalog}.php`
- Move: `src/Service/AccessHooks/{CurrentSchoolNames,PrincipalRoleChangeGuard}.php` → `src/Schools/Infrastructure/Access/`; `src/Service/BillingHooks/CurrentPlanUsage.php` → `src/Schools/Infrastructure/Billing/` (delete the emptied `BillingHooks` folder)
- Modify: `config/packages/doctrine.yaml` (mapping `Schools`), `config/packages/twig.yaml` (`src/Schools/Presentation/templates` as `Schools`; create the folder), `config/deptrac.yaml`, `phpunit.dist.xml` (`<source>` adds `src/Schools`), `config/phpcs.xml` (adds `src/Schools`, `tests/Schools`), `src/Form/TeacherSchoolFormType.php` (query builder takes `EntityRepository`), every legacy file and test naming a moved class
- Move tests: `tests/Data/PhilippineDivisionsTest.php` → `tests/Schools/Unit/Domain/`, `tests/Attendance/SchoolCalendarTest.php` → `tests/Schools/Integration/Domain/`
- Test (new): `tests/Schools/Integration/Infrastructure/{SchoolRepositoryTest,SchoolYearRepositoryTest,SchoolAssignmentRepositoryTest,DivisionRepositoryTest,SchoolDayOffRepositoryTest}.php`, `tests/Schools/Unit/Domain/{SchoolTest,SchoolYearTest,SchoolTypeTest,GradeLevelTest,GradingSystemTest,DivisionTest}.php`, `tests/Billing/Integration/Api/PlanCatalogTest.php`

**Interfaces:**
- Produces (no repository method flushes; `add`/`remove` only persist/remove):
  - `SchoolRepositoryInterface`: `findById(int): ?School`, `findBySchoolId(string): ?School`, `findAllOrdered(): list<School>`, `countAll(): int`, `countExpiringBy(\DateTimeImmutable): int`, `countActiveWithoutPrincipal(SchoolYear): int`, `countByPlan(): array<int, int>`, `add(School): void`.
  - `SchoolYearRepositoryInterface`: `findById(int): ?SchoolYear`, `findByName(string): ?SchoolYear`, `findCurrent(): ?SchoolYear`, `findAllNewestFirst(): list<SchoolYear>`, `findPrevious(SchoolYear): ?SchoolYear`, `makeCurrent(SchoolYear): void`, `add(SchoolYear): void`.
  - `SchoolAssignmentRepositoryInterface`: today's public methods of `SchoolAssignmentRepository` with the same signatures, plus `add(SchoolAssignment): void`, `remove(SchoolAssignment): void`.
  - `DivisionRepositoryInterface`: `findById(int): ?Division`, `findAllOrdered(): list<Division>`, `findForSchool(School): ?Division`, `findByRegionAndName(string $region, string $name): ?Division`, `add(Division): void`.
  - `SchoolDayOffRepositoryInterface`: `findById(int): ?SchoolDayOff`, `findBetween(School, \DateTimeImmutable, \DateTimeImmutable): array<string, SchoolDayOff>`, `findForYear(School, SchoolYear): list<SchoolDayOff>`, `add(SchoolDayOff): void`, `remove(SchoolDayOff): void`.
  - `PlanCatalogInterface` (Billing Api): `findById(int): ?Plan`, `findByName(string): ?Plan`, `findAllOrdered(): list<Plan>`; `PlanCatalog` implements it with `PlanRepositoryInterface`.
- Legacy calls change as follows: `find($id)` → `findById($id)`, `$schools->count([])` → `countAll()`, `findOneBy(['schoolId' => …])` → `findBySchoolId(…)`. Tests that use `$em->getRepository(X::class)` keep Doctrine's plain methods.
- deptrac: layers `Schools_Domain`, `Schools_Application`, `Schools_Infrastructure`, `Schools_Presentation` shaped like Billing's, plus `Billing_Api` (`^App\\Billing\\Application\\Api\\.*`, excluded from `Billing_Application`; may use `Billing_Domain`, `Core_Domain`; `Billing_Application` may use it). Schools layers may use `Access_Domain`, `Billing_Domain`; `Schools_Application` and `Schools_Presentation` also `Billing_Api`; `Schools_Infrastructure` also the Access/Billing Domain service interfaces it implements. `Legacy` excludes `Schools\\` and may use every Schools layer and `Billing_Api`.

- [ ] **Step 1: Write the tests.** Repository tests (KernelTestCase, `SchoolFixturesTrait`): one test per interface method, covering the null/empty cases (`testFindsBySchoolId`, `testCountExpiringByIncludesExpiredAndSkipsInactive`, `testMakeCurrentLeavesOneCurrent`, `testFindPreviousIsTheYearBefore`, `testFindBetweenIsKeyedByDate`, `testAddAndRemoveWaitForFlush`, …); the assignment repository tests keep the "account must still be a teacher" rule (`testTeacherCountsSkipPromotedAndInactiveTeachers`). Unit tests: every entity/enum method today's tests don't reach (`School` limits, `isPlanExpired` on its last day, `getPlanLabel` with 1 and 2 seats, blank-to-null setters; `SchoolYear::rebuildGradingPeriods` splits evenly and reuses periods by number, throws without dates; `SchoolType::withArticle()` "an Elementary school"/"a Junior High School"; `Division::setSdsPosition('')` gives the SDS title). `PlanCatalogTest`: the seeded `Basic` by name, by id, list ordered by name.
- [ ] **Step 2: Move the classes, add interfaces, Doctrine repositories, the Billing API, config and deptrac.** Rewrite imports with a scratchpad script (old FQCN → new FQCN, repository class → interface; add `use` lines where `App\Entity` classes used a moved class without importing it); grep `src/`, `tests/` and `config/` afterwards for every old FQCN and short repository name (DQL strings included) and fix what remains.
- [ ] **Step 3: Setup checks:** `cache:clear`, `lint:container`, `doctrine:schema:validate`, `doctrine:migrations:diff --allow-empty-diff -n` → no changes.

### Task 2: School and division administration

**Files:**
- Create: `src/Schools/Application/UseCase/Command/{CreateSchoolCommand,UpdateSchoolCommand,ToggleSchoolActiveCommand,CreateDivisionCommand,UpdateDivisionCommand}.php`, `src/Schools/Application/Query/Command/{ListSchoolsCommand,GetSchoolFormCommand,ListDivisionsCommand,GetDivisionCommand}.php`, their handlers, `src/Schools/Application/Transformer/{SchoolTransformer,DivisionTransformer,SchoolYearTransformer}.php`, `src/Schools/Domain/Exception/{SchoolNotFoundException,SchoolIdTakenException,PrincipalBlocksPlanException,SchoolYearNotFoundException,DivisionNotFoundException,DivisionTakenException}.php`, `src/Schools/Presentation/Controller/{SchoolController,DivisionController}.php`, `src/Schools/Presentation/Form/{SchoolFormType,DivisionFormType}.php` (moved; class names kept), `src/Schools/Presentation/Validation/{UniqueSchoolId,UniqueSchoolIdValidator,UniqueDivision,UniqueDivisionValidator}.php`
- Move templates: `templates/admin/schools/{index,form}.html.twig`, `templates/admin/divisions/{index,form}.html.twig` → `src/Schools/Presentation/templates/admin/…`
- Modify: `src/Controller/Admin/SchoolController.php` → renamed `SchoolPrincipalController.php` with only `principal`, `removePrincipal`, `copyPrincipals` (same class-level route prefix and `IsGranted`)
- Move tests: `tests/Controller/Admin/{SchoolControllerTest,DivisionControllerTest}.php` → `tests/Schools/Functional/`
- Test (new): `tests/Schools/Unit/Application/{SchoolHandlersTest,DivisionHandlersTest}.php`, `tests/Schools/Unit/Presentation/{UniqueSchoolIdValidatorTest,UniqueDivisionValidatorTest}.php`, new cases in the moved controller tests

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `SchoolTransformer::toArray(School, \DateTimeImmutable $today): SchoolData` with the keys the moved templates read: `id`, `schoolId`, `name`, `type` (enum or null), `region`, `division`, `district`, `address`, `active`, `planId`, `planName`, `planLabel`, `includesPrincipal`, `seats`, `teacherLimit`, `planValidUntil`, `planExpired`, `logoPath`, `idSignaturePath`, `idSignatureById`, the eleven profile text fields. Templates switch `school.planExpired(today)` → `school.planExpired`, `school.plan.includesPrincipal` → `school.includesPrincipal`.
  - `ListSchoolsCommand(?int $schoolYearId)` → `['schools' => list<SchoolData>, 'schoolYears' => list<SchoolYearData>, 'schoolYear' => ?SchoolYearData, 'principals' => array<int, array{user: array{id: int, fullName: string, active: bool}}>, 'teacherCounts' => array<int, int>, 'previousYear' => ?SchoolYearData, 'today' => \DateTimeImmutable]` (unknown id → `SchoolYearNotFoundException`, 404). `previousYear` only while the year has no principals, as today.
  - `GetSchoolFormCommand(?int $schoolId)` → `['school' => ?SchoolData, 'plans' => list<array{id: int, name: string, teachersPerSeat: ?int, studentsPerSeat: ?int}>, 'basicPlanId' => ?int]`; unknown id → `SchoolNotFoundException` (404).
  - `CreateSchoolCommand` (public `string $schoolId = ''`, `string $name = ''`, `?SchoolType $type`, `?string $region`, `?string $division`, `?string $district`, `?string $address`, `?int $planId`, `?int $seats = 1`, `?\DateTimeImmutable $planValidUntil`; the entity's constraints with the same messages, `planId` `NotNull(message: 'Choose a plan.')`; class constraint `UniqueSchoolId`) → `SchoolIdTakenException` ("This School ID already exists.", `SCHOOL_ID_TAKEN`) on a race; logs "Created school <schoolId> <name>"; returns `['name' => string]`. `UpdateSchoolCommand` adds `public readonly int $id` and `fromSchool(array $school): self`; refuses with `SchoolNotFoundException`, `SchoolIdTakenException`, `PrincipalBlocksPlanException` (today's message); logs "Updated school …". `ToggleSchoolActiveCommand(int $id)` logs "Activated/Deactivated school …", returns `['name' => string, 'active' => bool]`.
  - `SchoolFormType`: option `plans` (the list above) builds the `plan` field as a `ChoiceType` of plan ids (`property_path: 'planId'`, same labels `'%s — %d teacher(s), %s students per seat'`, same placeholder), so the HTML matches today's `EntityType`. The region/division listeners read and write the command.
  - Division: `CreateDivisionCommand`/`UpdateDivisionCommand` (`region`, `name`, `sdsName`, `sdsPosition`, `?string $signatureFile` = uploaded file's path; today's messages; class constraint `UniqueDivision` with "This division is already on the list." on `name`) → `DivisionTakenException` on a race, `ImageProcessingException` from `storeSignature()`; flash/log "Added division <name>." / "Saved division <name>.". `ListDivisionsCommand()`, `GetDivisionCommand(int $id)` (404 when missing).
  - Controllers keep routes, methods, flashes and CSRF id values; `ImageProcessingException` goes under `signature`, `SchoolIdTakenException` under `schoolId`, `PrincipalBlocksPlanException` under `plan`, `DivisionTakenException` under `name`.

- [ ] **Step 1: Move the tests** (assertions unchanged; namespace `App\Tests\Schools\Functional`) and add: `testUnknownSchoolIs404` (edit GET, toggle with a valid token after removing the school), `testUnknownSchoolYearInListIs404`, `testDuplicateSchoolIdIsRefusedWithTheOtherErrors`, `testUnknownDivisionIs404`, `testBadSignatureShowsUnderSignature`. Handler tests: every handler's success and refusal paths and log lines (`testUpdateRefusesAPlanWithoutPrincipalWhileOneIsAssigned`, `testUpdateAllowsItWithoutACurrentYear`, `testCreateRefusesATakenSchoolId`, `testUpdateKeepsItsOwnSchoolId`, `testDivisionSignatureIsStoredAndOldOneReplaced`). Validator tests as Billing's `UniquePlanNameValidatorTest` (taken/free, own row on edit, blank, wrong constraint, wrong value).
- [ ] **Step 2: Implement** commands, handlers, transformers, exceptions, constraints, forms, controllers, templates.
- [ ] **Step 3: Setup checks:** `lint:twig` on all template folders, route table identical to HEAD (scratchpad route dump).

### Task 3: School years

**Files:**
- Create: `src/Schools/Domain/Service/SchoolYear/GradingPeriodUsageInterface.php`, `src/Service/SchoolsHooks/CurrentGradingPeriodUsage.php` (legacy), `src/Schools/Application/UseCase/Command/{CreateSchoolYearCommand,UpdateSchoolYearCommand,GradingPeriodInput,MakeSchoolYearCurrentCommand}.php`, `src/Schools/Application/Query/Command/{ListSchoolYearsCommand,GetSchoolYearFormCommand}.php`, their handlers, `src/Schools/Domain/Exception/{SchoolYearNameTakenException,GradingSystemLockedException,GradedPeriodRemovalException}.php`, `src/Schools/Presentation/Controller/SchoolYearController.php`, `src/Schools/Presentation/Form/{SchoolYearFormType,GradingPeriodFormType}.php` (moved), `src/Schools/Presentation/Validation/{UniqueSchoolYearName,UniqueSchoolYearNameValidator}.php`
- Move templates: `templates/admin/school_years/{index,form}.html.twig` → `src/Schools/Presentation/templates/admin/school_years/`
- Delete: `src/Controller/Admin/SchoolYearController.php`, `src/Form/{SchoolYearFormType,GradingPeriodFormType}.php`
- Move tests: `tests/Controller/Admin/SchoolYearControllerTest.php` → `tests/Schools/Functional/`
- Test (new): `tests/Schools/Unit/Application/{SchoolYearHandlersTest,UpdateSchoolYearCommandTest}.php`, `tests/Schools/Unit/Presentation/UniqueSchoolYearNameValidatorTest.php`, `tests/Schools/Integration/Infrastructure/CurrentGradingPeriodUsageTest.php`

**Interfaces:**
- Consumes: Tasks 1–2 (`SchoolYearTransformer`).
- Produces:
  - `GradingPeriodUsageInterface`: `countSchoolsOpen(GradingPeriod): int`, `hasOpenPeriod(SchoolYear): bool`, `isGraded(GradingPeriod): bool` (grades or locks). `CurrentGradingPeriodUsage` implements it with `GradeOpeningRepository::countSchoolsOpen()`/`existsForSchoolYear()`, `GradeRepository::countForPeriod()` and the lock count.
  - `GetSchoolYearFormCommand(?int $id)` → the year as `SchoolYearData` with `gradingPeriods` (each `id`, `number`, `label`, `startDate`, `endDate`), or for null the suggestion (`name` "YYYY-YYYY+1", June 1 to April 30 after the latest year's end, as `suggestNextYear()`); unknown id → 404.
  - `CreateSchoolYearCommand` (`name`, `startDate`, `endDate`, `gradingSystem = Terms`; Regex "Use the format 2026-2027.", `GreaterThan(propertyPath: 'startDate')` "The end date must be after the start date.", class constraint `UniqueSchoolYearName` "This school year already exists.") → first year ever becomes current, periods rebuilt, logs "Created school year <name>", returns name, period count and period name for today's flash.
  - `UpdateSchoolYearCommand` adds `int $id` and `list<GradingPeriodInput> $gradingPeriods` (`int $number`, `?\DateTimeImmutable $startDate`, `?\DateTimeImmutable $endDate`); `validateGradingPeriods()` moves here as an `Assert\Callback` with today's messages and paths, skipped when the row count doesn't match the system. Handler: applies rows by number (row *i* is period *i*+1; adds missing, removes extra), then on a system change refuses with `GradingSystemLockedException` when `hasOpenPeriod()` (checked before the change) or rebuilds non-matching rows; refuses with `GradedPeriodRemovalException` for the lowest-numbered removed period that `isGraded()`, labelled with the old system's period name; logs "Updated school year <name>".
  - `MakeSchoolYearCurrentCommand(int $id)` logs "Set current school year to <name>". `ListSchoolYearsCommand()` → `['schoolYears' => list<SchoolYearData>, 'openCounts' => array<int, int>, 'schoolCount' => int]`.
  - The form's `gradingPeriods` collection uses `GradingPeriodInput` as data (`empty_data` builds `new GradingPeriodInput((int) $row->getName() + 1)`); `GradingSystemLockedException` and `GradedPeriodRemovalException` go under `gradingSystem`, `SchoolYearNameTakenException` under `name`.

- [ ] **Step 1: Move the test** and add `testUnknownSchoolYearIs404` (edit GET, make-current with a valid token after removing it). `SchoolYearHandlersTest`: create (first is current, second isn't), name taken, rebuild on switch, refusal while open, refusal for a graded removed period (and none for an ungraded one), rows applied by number, make current. `UpdateSchoolYearCommandTest` (validator): each of the four period messages with its path, skipped on mismatched rows. `CurrentGradingPeriodUsageTest`: open count, open in year, graded by grade and by lock.
- [ ] **Step 2: Implement** the hook, legacy hook, commands, handlers, constraint, forms, controller, templates.
- [ ] **Step 3: Setup checks:** `lint:twig`, route table identical to HEAD.

### Task 4: School profile, calendar and voter

**Files:**
- Move: `src/Security/Voter/SchoolVoter.php` → `src/Schools/Presentation/Security/SchoolVoter.php` (same constant `SCHOOL_MANAGE`); rewrite its imports in the 14 legacy controllers
- Create: `src/Schools/Domain/Service/Calendar/DayOffAttendanceInterface.php`, `src/Service/SchoolsHooks/RollCallDayOffAttendance.php` (legacy, `RollCallRepository::countSectionsOn()`), `src/Schools/Application/UseCase/Command/{UpdateSchoolProfileCommand,AddDaysOffCommand,RemoveDayOffCommand}.php`, `src/Schools/Application/Query/Command/{GetSchoolProfileCommand,GetSchoolCalendarCommand}.php`, their handlers, `src/Schools/Domain/Exception/{DayOffRangeException,DayOffNotFoundException}.php`, `src/Schools/Presentation/Controller/{SchoolProfileController,SchoolCalendarController}.php`, `src/Schools/Presentation/Form/{SchoolProfileFormType,DayOffFormType}.php` (moved)
- Move templates: `templates/principal/school_profile.html.twig`, `templates/attendance/calendar.html.twig` → `src/Schools/Presentation/templates/…` (they keep extending the legacy layouts)
- Delete: `src/Controller/Principal/SchoolProfileController.php`, `src/Controller/SchoolCalendarController.php`, `AttendanceRecorder::addDaysOff()`/`removeDayOff()`/`MAX_RANGE_DAYS` and the constructor arguments only they used
- Move tests: `tests/Security/SchoolVoterTest.php` → `tests/Schools/Integration/Presentation/`, `tests/Controller/Principal/SchoolProfileTest.php` → `tests/Schools/Functional/`, the calendar cases of `tests/Controller/SchoolAttendanceControllerTest.php` → `tests/Schools/Functional/SchoolCalendarControllerTest.php`, `AttendanceRecorderTest::testAddDaysOffRange`/`testRemoveDayOff` → `tests/Schools/Integration/Application/DayOffHandlersTest.php` (through the bus)
- Test (new): `tests/Schools/Unit/Application/SchoolProfileHandlersTest.php`, new cases in `DayOffHandlersTest` and the moved controller tests

**Interfaces:**
- Consumes: Tasks 1–2 (`SchoolTransformer`).
- Produces:
  - `GetSchoolProfileCommand(int $userId)` → `['school' => ?SchoolData, 'schoolYear' => ?SchoolYearData]` (the school the user is principal of in the current year; null renders `principal/not_assigned.html.twig` as today). `UpdateSchoolProfileCommand` (the profile fields with today's `Length`/`Email`/`Url(requireTld: true)` rules, `int $userId`, `?string $logoFile`, `?string $idSignatureFile`) → stores the logo, then the signature with `idSignatureBy` = that user; `ImageProcessingException` refuses (shown under `logo`); logs "Updated school profile <name>". The template compares `school.idSignatureById == app.user.id`.
  - `DayOffAttendanceInterface::countSectionsWithAttendanceOn(School, list<\DateTimeImmutable>): int`.
  - `GetSchoolCalendarCommand(int $schoolId)` → `['school' => SchoolData, 'schoolYear' => SchoolYearData, 'daysOff' => list<array{id: int, date: \DateTimeImmutable, reason: string}>]`; no current year → 404 "No current school year.".
  - `AddDaysOffCommand` (`int $schoolId`, `int $userId`, `?\DateTimeImmutable $from` `NotNull(message: 'Pick a date.')`, `?\DateTimeImmutable $to`, `string $reason` `NotBlank(message: 'Give a reason.')`, `Length(max: 100)`) → today's three range messages as `DayOffRangeException`, returns `['added' => int, 'sectionsWithAttendance' => int]`, same log line. `RemoveDayOffCommand(int $schoolId, int $dayOffId)` → `DayOffNotFoundException` (404) for a missing or other school's day off; returns the date.
  - The calendar controller keeps `#[IsGranted(SchoolVoter::MANAGE, 'school')]` with the `School` route argument, the layout switch and today's flashes.

- [ ] **Step 1: Move the tests** and add: `SchoolProfileHandlersTest` (fields saved and trimmed to null, logo stored and old replaced, signature records the uploader, image error saves nothing); `DayOffHandlersTest::testRefusesAReversedRange`, `testRefusesDaysOutsideTheSchoolYear`, `testRefusesMoreThan62Days`, `testSkipsWeekendsAndExistingDays`, `testCountsSectionsThatTookAttendance`, `testRemoveRefusesAnotherSchoolsDayOff`; `SchoolCalendarControllerTest::testWithoutCurrentYearIs404`.
- [ ] **Step 2: Implement** the hook, legacy hook, commands, handlers, forms, controllers, templates; move the voter.
- [ ] **Step 3: Setup checks:** `lint:container`, `lint:twig`, route table identical to HEAD.

### Task 5: Baselines, docs, comments, deprecations

- [ ] **Step 1:** phpcbf, then phpcs clean (wrap lines > 120 by hand, through scratchpad scripts). PHPStan on `src/Schools src/Billing src/Access src/Core tests/Schools tests/Billing tests/Access tests/Core` with no errors; then baseline regeneration under the Global Constraints rules; deptrac 0 violations and the deptrac baseline not grown.
- [ ] **Step 2: Docs.** `CLAUDE.md`: Schools module (what it holds; `School` etc. are `App\Schools\Domain\Model\…`; repositories through their interfaces; the hooks `GradingPeriodUsageInterface`/`DayOffAttendanceInterface` implemented in `src/Service/SchoolsHooks/`; Billing's public API `PlanCatalogInterface` and the `<Module>_Api` deptrac layer; people screens and `PlanLimits` still legacy until 2e). Domain-terms lines for plans/seats and school years updated.
- [ ] **Step 3: Comment sweep and deprecation check** (`debug:container --deprecations`) over everything touched.

### Task 6: Verification

- [ ] **Step 1: Scoped run:** `make bash-exec CMD='bash docker/test/run-feature-tests.sh tests/Schools tests/Billing tests/Access tests/Core tests/Controller tests/Security tests/Attendance'`; fix, re-run only failures.
- [ ] **Step 2: Coverage check on module tests alone:** `php -d pcov.enabled=1 bin/phpunit tests/Schools tests/Billing tests/Access tests/Core --coverage-clover …` and a scratchpad `uncovered.py` → nothing uncovered.
- [ ] **Step 3: Full runs on the final code, one at a time:** `make test all` (8/8), `make test-feature` (100.00%), `make regression-test` (5); schema valid, diff empty; route table identical to HEAD.
- [ ] **Step 4: Fresh whole-change review** (most capable model, read-only); fix Critical/Important with a failing test first; full runs again.
- [ ] **Step 5: Ask the owner before the live-browser check, and whether headless or visible** (default visible). If headless: HEAD-vs-branch screenshots of the moved pages and the SF/ID PDFs, plus flows: create/edit/toggle a school (region/division mismatch, plan without principal), add/edit a division with a signature, create a school year and switch terms↔quarters, edit the school profile with a logo, add/remove days off.
- [ ] **Step 6: Report** and propose the commit; commit only after approval:

```
Move schools, school years and divisions into the Schools module

Move the school entities, their repositories, the school calendar and the
admin school, division and school-year screens and the principal's school
profile into a Schools module whose actions all go through the bus. Grades
and roll calls reach it through hooks, plans through a Billing API. Pages,
messages and URLs are unchanged.
```
