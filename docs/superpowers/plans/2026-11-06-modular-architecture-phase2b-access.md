# Modular architecture, Phase 2b (Access) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move accounts and sign-in into an `Access` module with DDD layers, every action through the Core bus, without anything a user would notice changing.

**Architecture:** `src/Access/` gets the four layers in the Core layout (`Domain/Model`, `Domain/Model/Repository`, `Domain/Service/<Area>`, `Domain/Exception`, `Application/UseCase|Query/{Command,Handler}`, `Application/Transformer`, `Infrastructure/<Area>`, `Presentation/{Controller,Form,EventListener,Console,templates}`). Rules owned by lower modules (Schools, Billing, Learners) reach Access through hook interfaces in `Access/Domain/Service/Account/`, implemented for now by legacy classes in `src/Service/AccessHooks/`. Core gains locks that stay held until a use case's transaction ends, and a controller base that keeps unexpected failures as error pages.

**Tech Stack:** Symfony 8.1 (Security, Form, Validator, Lock, Console), PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Twig, PHPUnit 13, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row `Access`, sections 5, 6, 8, 10). Owner decisions (2026-11-06): the Domain `User` keeps implementing Symfony's `UserInterface` and `PasswordAuthenticatedUserInterface` (a narrow deptrac exception, nothing else from Symfony in Domain); lower-module rules go through Access hooks; the teacher-school routes stay with Schools.

**Branch:** `feature/modular-architecture` (Phase 2a committed as `5cc4609`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 1. Core: transaction locks and bus controller | 23:57:47 | 23:59:07 | 1m |
| 2. Access domain, persistence and wiring | 23:59:07 | 00:07:27 | 8m |
| 3. Sign-in and password change | 00:07:27 | 00:09:42 | 2m |
| 4. Account administration | 00:09:42 | 00:14:53 | 5m |
| 5. Create-user console command | 00:14:53 | 00:15:27 | 1m |
| 6. Profile photo | 00:15:27 | 00:18:10 | 3m |
| 7. Baselines, docs, comments, deprecations | 00:19:11 | 00:28:31 | 9m |
| 8. Verification, review and fixes | 00:28:31 | 01:30:44 | 62m |

## Global Constraints

- Structure only: every page, URL, route name, form field name, flash message, error text and activity-log line stays as today. Table and column names stay; `doctrine:migrations:diff` stays empty.
- Access may use Core, nothing else (no `App\Entity\*` other than its own moved class, no `App\Service`, `App\Repository`, `App\Form`). Legacy may use Access. Hooks are the only way lower-module rules reach Access.
- Access Domain may use: `Doctrine\ORM\Mapping`, `Doctrine\Common\Collections`, `Symfony\Component\Security\Core\User\UserInterface`, `Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface`, and Core Domain. Nothing else from Symfony.
- Controllers make one bus call per action and build the response from `$result->data()`. A failed `Result` whose exception is a `DomainException` becomes the same flash or form error as today; any other failure is rethrown (an error page, as today).
- Use case handlers report refusals by throwing an Access/Core `DomainException` (never `Result::failure()`); handlers never call other handlers.
- Forms bind to the bus Command objects (mutable public properties), not entities; field names unchanged.
- New code: `declare(strict_types=1)`, PHPStan level 9 with no baseline entries, phpcs PSR-12, 100% coverage of `src/Core` and `src/Access`.
- Tests that move keep their assertions; only imports, class names, routes-as-strings already used, and helpers change. New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first) and the existing helpers (`AdminTestCase`, `SchoolFixturesTrait`).
- Write each task's tests during the task; don't run tests per task. All runs in Task 8, one command at a time, Docker only. `MSYS_NO_PATHCONV=1` for `docker exec` with container paths. Every deptrac command passes `--cache-file=reports/deptrac/.cache`.
- PHPStan baseline: delete `reports/phpstan/.cache` before regenerating; the error total must not grow (record it in Task 7) and no entry may name `src/Access`, `tests/Access`, `src/Core` or `tests/Core`, or a file not on `develop`.
- Comments short and plain, no references to plans or tools. No Claude/Anthropic mention anywhere. One commit at the end after approval, through the Base hook.

## Review Focus

1. **Two admins reactivating teachers into a school's last seat at the same moment.** One must get "Another change to this school's teachers is being saved. Please try again." — the lock has to stay held until the transaction commits, not just until the handler returns. Pinned by `TransactionLockTest::testLockIsHeldUntilTheUseCaseCommits` (Task 1).
2. **An unexpected error inside an admin action.** Today that is an error page; it must not turn into a silent redirect or a success flash. Pinned by `BusControllerTest::testUnexpectedFailureIsRethrown` (Task 1).
3. **Duplicate usernames.** Creating or renaming an account to a taken username shows "This username is already taken." under Username, from the admin screen and from the principal's add-teacher form (which keeps today's checks after they leave the entity). Pinned by `AccountControllerTest::testDuplicateUsernameIsRefused` (Task 4) and `SchoolTeacherControllerTest::testDuplicateUsernameIsRefused` (Task 2).
4. **Changing the password to the current one.** Shows "Your new password must be different from your current one." under the first field and keeps the old password. Pinned by `SecurityControllerTest::testNewPasswordMustDifferFromTheCurrentOne` (Task 3).
5. **Signing in still records the time and the activity line**, and a disabled account still can't sign in. Pinned by `SecurityControllerTest::testSignInRecordsTheTimeAndActivity` (Task 3) and the moved `testDisabledAccountCannotSignIn`.

## Rulings in this plan

- **Locks held until commit (Core).** Every legacy lock (`SchoolTeacherLock`, `LearnerLock`, timetable, attendance, grades, nutrition) is "try, refuse if busy, release after the save". On the bus the save commits after the handler returns, so Core gets `TransactionLockInterface`, released by a middleware placed outside `doctrine_transaction`. Same lock keys as the legacy locks, so old and new code exclude each other.
- **Custom user provider.** Without `repositoryClass` on the entity, Symfony's entity provider can't rehash passwords on sign-in; `AccountUserProvider` (Infrastructure) does what the entity provider did, including the upgrade.
- **`UserRepository::findAssignablePrincipals()`** names `SchoolYear`, so it moves to the legacy `SchoolAssignmentRepository` with the same signature. **`countActiveAdmins()`** has no callers and is dropped.
- **`TeacherFormType`** (principal's add-teacher form, legacy) binds to `User`; the entity's `Assert` and `UniqueEntity` rules move onto that form with the same messages, so it validates as today.
- **`ImageUploadFormType` and `profile/_photo_form.html.twig`** are used by the profile and by learners, so they move to Core (`Core/Presentation/Form`, `@Core/_photo_form.html.twig`).
- **Access templates keep extending the legacy role layout** (`admin/_layout.html.twig`) until Dashboards moves it.
- **Deactivating** no longer takes the school lock (only reactivation needs a seat); the outcome is the same.

---

### Task 1: Core: transaction locks and bus controller

**Files:**
- Create: `src/Core/Domain/Service/Lock/TransactionLockInterface.php`, `src/Core/Domain/Exception/ResourceBusyException.php`, `src/Core/Infrastructure/Lock/TransactionLock.php`, `src/Core/Infrastructure/Bus/Middleware/ReleaseLocksMiddleware.php`, `src/Core/Presentation/Controller/BusController.php`
- Modify: `config/packages/messenger.yaml` (`usecase.bus` middleware: Logging, ExceptionHandling, `ReleaseLocksMiddleware`, `doctrine_transaction`, validation)
- Test: `tests/Core/Unit/Infrastructure/Bus/Middleware/ReleaseLocksMiddlewareTest.php`, `tests/Core/Integration/Infrastructure/Lock/TransactionLockTest.php`, `tests/Core/Unit/Presentation/Controller/BusControllerTest.php` (+ fixtures in `tests/Core/Integration/Bus/Fixture/`)

**Interfaces:**
- Produces:
  - `TransactionLockInterface::acquire(string $resource, string $busyMessage): void` — takes the lock without waiting; throws `ResourceBusyException($busyMessage)` (error code `RESOURCE_BUSY`) when someone else holds it. Only for use inside use case handlers.
  - `TransactionLock` (LockFactory) also has `releaseAll(): void`.
  - `ReleaseLocksMiddleware` calls `releaseAll()` in a `finally` after the rest of the stack (so after commit or rollback).
  - `BusController extends AbstractController`: `protected function domainFailure(Result $result): ?DomainException` — null on success, the `DomainException` on a domain failure, rethrows any other exception.

- [ ] **Step 1: Write the tests.**
  - `TransactionLockTest` (KernelTestCase, fixture use case `LockThenWaitCommand` whose handler acquires `'core-test'` and records whether a second `LockFactory` lock on the same key can be acquired): `testLockIsHeldUntilTheUseCaseCommits` (inside the handler a second lock fails; after `handle()` returns it succeeds); `testBusyResourceIsRefused` (`// Expect` nothing; hold `'core-test'` with a `LockFactory` lock, dispatch the fixture → `Result` failure with code `'RESOURCE_BUSY'` and the busy message); `testLocksAreReleasedAfterAFailure` (handler acquires then throws → lock free afterwards).
  - `ReleaseLocksMiddlewareTest`: `testReleasesAfterTheStack` and `testReleasesWhenTheStackThrows` (mock `TransactionLock`, `releaseAll` once).
  - `BusControllerTest` (subclass exposing `domainFailure`): `testSuccessGivesNull`, `testDomainFailureIsReturned`, `testUnexpectedFailureIsRethrown` (`// Expect` `\RuntimeException`).
- [ ] **Step 2: Implement** the classes and the middleware order.

### Task 2: Access domain, persistence and wiring

**Files:**
- Move: `src/Entity/User.php` → `src/Access/Domain/Model/User.php` (`App\Access\Domain\Model\User`; drop `repositoryClass`, `Assert` and `UniqueEntity`; keep `#[ORM\Table(name: '`user`')]`, constants, methods and `__serialize`)
- Create: `src/Access/Domain/Model/Repository/UserRepositoryInterface.php`, `src/Access/Infrastructure/Repository/DoctrineUserRepository.php`, `src/Access/Infrastructure/Security/AccountUserProvider.php`, `src/Access/Domain/Service/Password/PasswordHasherInterface.php`, `src/Access/Infrastructure/Security/SymfonyPasswordHasher.php`, `src/Access/Domain/Service/Password/TemporaryPasswordGenerator.php` (moved from `src/Service`), hooks `src/Access/Domain/Service/Account/{AccountRoleChangeGuardInterface,AccountReactivationGuardInterface,AccountLearnerLinkInterface,AccountSchoolNamesInterface}.php`, exceptions `src/Access/Domain/Exception/{UsernameTakenException,PasswordUnchangedException,AccountRoleChangeRefusedException,AccountReactivationRefusedException,OwnAccountException,AccountNotFoundException}.php`, legacy hook implementations `src/Service/AccessHooks/{PrincipalRoleChangeGuard,LearnerRoleChangeGuard,LearnerAccountLink,TeacherSeatReactivationGuard,CurrentSchoolNames}.php`
- Delete: `src/Repository/UserRepository.php`, `src/Service/TemporaryPasswordGenerator.php`
- Modify: `config/packages/doctrine.yaml` (mapping `Access`: `src/Access/Domain`, prefix `App\Access\Domain`), `config/packages/security.yaml` (provider `id: App\Access\Infrastructure\Security\AccountUserProvider`), `config/packages/twig.yaml` (path `src/Access/Presentation/templates` as `Access`), `config/deptrac.yaml`, `phpunit.dist.xml` (`<source>` adds `src/Access`), `config/phpcs.xml` (adds `src/Access`, `tests/Access`), `src/Repository/SchoolAssignmentRepository.php` (gains `findAssignablePrincipals(SchoolYear $schoolYear, ?User $include = null): array`, body moved), its two callers, `src/Form/TeacherFormType.php`, every file that imports or names `App\Entity\User` (181, plus the 25 entities in `App\Entity` that use `User` unimported, plus `constant('App\\Entity\\User::...')` in templates)
- Test: `tests/Access/Integration/Infrastructure/DoctrineUserRepositoryTest.php`, `tests/Access/Integration/Infrastructure/AccountUserProviderTest.php`, `tests/Access/Unit/Domain/TemporaryPasswordGeneratorTest.php`, `tests/Access/Unit/Domain/UserTest.php`, `SchoolTeacherControllerTest::testDuplicateUsernameIsRefused`

**Interfaces:**
- Produces:
  - `UserRepositoryInterface`: `findById(int $id): ?User`, `findOneByUsername(string $username): ?User` (lower-cased, trimmed), `add(User $user): void` (persist, no flush), `search(?string $query, ?string $role, ?bool $active, int $page, int $perPage): array{items: list<User>, total: int}` (today's query), `countByRole(string $role): int`, `countInactive(): int`.
  - `DoctrineUserRepository` implements it and `PasswordUpgraderInterface` (today's `upgradePassword`).
  - `AccountUserProvider` implements `UserProviderInterface` and `PasswordUpgraderInterface`: load by username, refresh by id, supports `User`, upgrade through the repository.
  - `PasswordHasherInterface`: `hash(User $user, string $plainPassword): string`, `isValid(User $user, string $plainPassword): bool`.
  - Hooks (tagged where several may exist, injected with `#[AutowireIterator]` in handlers):
    - `AccountRoleChangeGuardInterface::refusal(User $account, ?string $originalRole): ?string` — called after the new role is set; returns today's error text or null.
    - `AccountReactivationGuardInterface::assertCanReactivate(User $account): void` — may acquire transaction locks; throws `AccountReactivationRefusedException` or `ResourceBusyException`.
    - `AccountLearnerLinkInterface::learnerLrn(User $account): ?string` (single implementation).
    - `AccountSchoolNamesInterface::schoolNamesByAccount(): array<int, string>` (single; today's `findSchoolNamesByUser(current year)`, `[]` without a current year).
  - Exceptions (each extends Core `DomainException`; code / message): `UsernameTakenException` `USERNAME_TAKEN` "This username is already taken."; `PasswordUnchangedException` `PASSWORD_UNCHANGED` "Your new password must be different from your current one."; `AccountRoleChangeRefusedException` `ROLE_CHANGE_REFUSED` (reasons in `data()['reasons']`, message joins them); `AccountReactivationRefusedException` `REACTIVATION_REFUSED` (message from the guard); `OwnAccountException` `OWN_ACCOUNT` (message given); `AccountNotFoundException` `ACCOUNT_NOT_FOUND`.
  - Legacy hooks reproduce today's checks exactly: principal guard = `findUnfinishedPrincipalAssignment` message; learner guard = "This account belongs to learner %s." when the role changed; seat guard = teacher's current school, `TransactionLockInterface::acquire('school-teachers-'.$school->getId(), "Another change to this school's teachers is being saved. Please try again.")`, then `PlanLimits::canAddTeacher` refusal.
- deptrac: layers `Access_Domain`, `Access_Application`, `Access_Infrastructure`, `Access_Presentation` (same shape as Core's) and `SecurityUserContract` (`^Symfony\\Component\\Security\\Core\\User\\(UserInterface|PasswordAuthenticatedUserInterface)$`, excluded from `Framework`); `Legacy` becomes `^App\\(?!Core\\|Access\\|Kernel$)`. Ruleset: `Access_Domain: [Core_Domain, DoctrineMapping, SecurityUserContract]`; `Access_Application: [Access_Domain, Core_Application, Core_Domain, Core_BusAttributes, ValidatorConstraints]`; `Access_Infrastructure: [Access_Domain, Access_Application, Core_Domain, Core_Application, Core_BusAttributes, DoctrineMapping, SecurityUserContract, ValidatorConstraints, Framework]`; `Access_Presentation: [Access_Application, Access_Domain, Core_Application, Core_Domain, Core_Presentation, SecurityUserContract, ValidatorConstraints, Framework]`; `Legacy` adds the Access layers; Core layers unchanged (Core uses no module).

- [ ] **Step 1: Write the tests.** `DoctrineUserRepositoryTest`: `testSearchFiltersAndPages` (role, active, query on name/username, newest first, `total`), `testCounts`, `testFindOneByUsernameIgnoresCaseAndSpaces`, `testUpgradePasswordStoresTheNewHash`. `AccountUserProviderTest`: `testLoadsByUsername`, `testUnknownUsernameIsNotFound` (`// Expect` `UserNotFoundException`), `testRefreshReloadsFromTheDatabase`, `testSupportsOnlyUser`, `testUpgradesThePassword`. `TemporaryPasswordGeneratorTest::testFormat` (matches `/^[a-hj-km-np-z]{4}-[2-9]{4}$/`). `UserTest` (role, labels, initials, serialized password hash — existing behaviour). `SchoolTeacherControllerTest::testDuplicateUsernameIsRefused` (principal adds a teacher with a taken username → "This username is already taken." under Username).
- [ ] **Step 2: Move the entity and repository, add the ports, hooks, exceptions and legacy hooks**, the provider and config. Rewrite imports with the scratchpad scripts (`rewrite.py`, `adduse.py`; entities in `App\Entity` get an import); grep afterwards for `App\Entity\User`, `App\\Entity\\User` and unimported `User`.
- [ ] **Step 3: Move `TeacherFormType`'s validation** onto the form: field constraints as on the entity today (same messages) and `new UniqueEntity(fields: ['username'], entityClass: User::class, message: 'This username is already taken.')` as a form-level constraint.
- [ ] **Step 4: Setup checks** (not test runs): `cache:clear`, `doctrine:schema:validate`, `doctrine:migrations:diff --allow-empty-diff -n` → no changes; `debug:router` route count unchanged (record before/after).

### Task 3: Sign-in and password change

**Files:**
- Move to `src/Access/Presentation/`: `Controller/SecurityController.php` (extends `BusController`; same routes), `Form/ChangePasswordFormType.php`, `EventListener/ForcePasswordChangeListener.php` (redirect part only), templates `security/*` → `templates/security/*` (`@Access/security/...`)
- Create: `src/Access/Presentation/EventListener/SignInListener.php` (on `LoginSuccessEvent` dispatches `RecordSignInCommand`), `src/Access/Infrastructure/Security/UserChecker.php` (moved), `src/Access/Application/UseCase/Command/{RecordSignInCommand,ChangePasswordCommand}.php`, handlers in `Application/UseCase/Handler/`
- Move tests: `tests/Controller/SecurityControllerTest.php` → `tests/Access/Functional/SecurityControllerTest.php`
- Test (new): `SecurityControllerTest::testSignInRecordsTheTimeAndActivity`, `::testNewPasswordMustDifferFromTheCurrentOne`; unit tests for both handlers

**Interfaces:**
- Consumes: Task 1 `BusController`; Task 2 repository, `PasswordHasherInterface`, exceptions; Core `ActivityLoggerInterface`.
- Produces: `RecordSignInCommand(int $userId)` → sets `lastLoginAt` to now, logs "Signed in" with the username. `ChangePasswordCommand(int $userId, string $newPassword)` → `PasswordUnchangedException` when it matches the current one, else new hash, `mustChangePassword` false, logs "Changed password". The current-password check stays in the form (`UserPassword` constraint) as today.

- [ ] **Step 1: Move the test** (assertions unchanged) and add: `testSignInRecordsTheTimeAndActivity` (after signing in, `lastLoginAt` is set and `activityActions()` contains "Signed in"); `testNewPasswordMustDifferFromTheCurrentOne` (submit the current password as new → error under the first field, old password still signs in). Unit: handler tests for both commands (user not found → `AccountNotFoundException`).
- [ ] **Step 2: Implement**; `security.yaml` `user_checker` points at the moved class.

### Task 4: Account administration

**Files:**
- Create: `src/Access/Presentation/Controller/AccountController.php` (extends `BusController`; routes `admin_accounts`, `admin_accounts_by_role`, `admin_account_new`, `admin_account_edit`, `admin_account_toggle_active`, `admin_account_reset_password` with today's paths, methods and CSRF ids — the CSRF expressions use `args["id"]`), `src/Access/Presentation/Form/AccountFormType.php` (moved `UserAccountFormType`, `data_class` the command), `src/Access/Application/UseCase/Command/{CreateAccountCommand,UpdateAccountCommand,ToggleAccountActiveCommand,ResetAccountPasswordCommand}.php`, `src/Access/Application/Query/Command/{SearchAccountsCommand,GetAccountCommand}.php`, their handlers, `src/Access/Application/Transformer/AccountTransformer.php`, `src/Controller/Admin/TeacherSchoolController.php` (legacy; routes `admin_account_school` and `admin_account_school_remove` moved here unchanged)
- Move templates: `admin/accounts/index.html.twig`, `form.html.twig` → `src/Access/Presentation/templates/admin/accounts/` (`admin/accounts/school.html.twig` stays)
- Delete: `src/Controller/Admin/UserAccountController.php`, `src/Form/UserAccountFormType.php`
- Move tests: account tests of `tests/Controller/Admin/UserAccountControllerTest.php` → `tests/Access/Functional/AccountControllerTest.php`; the teacher-school tests (`testAdminAssignsTeacherToSchool`, `testAdminMovesTeacherToAnotherSchool`, `testAdminAssignRefusedWhenSchoolIsFull`, `testAdminRemovesTeacherFromSchool`, `testTeacherWithLoadsCannotBeRemovedFromSchool`, `testTeacherWithLoadsCannotBeMoved`, `testOnlyTeachersCanBeAssignedToASchool`) → `tests/Controller/Admin/TeacherSchoolControllerTest.php`
- Test (new): `AccountControllerTest::testDuplicateUsernameIsRefused`; unit tests per handler

**Interfaces:**
- Consumes: Tasks 1-2.
- Produces:
  - `CreateAccountCommand` (public `?string $role`, `string $fullName`, `string $username`, `string $temporaryPassword`; constraints: username `NotBlank`, `Length(min: 3, max: 50)`, `Regex('/^[a-zA-Z0-9._-]+$/', message: 'Use only letters, numbers, dots, dashes and underscores.')`; fullName `NotBlank`, `Length(max: 120)`; role `NotBlank(message: 'Please choose a role.')`) → `['id' => int, 'fullName' => string, 'username' => string, 'role' => string]`; refuses a taken username; logs "Created account: <username>"; `mustChangePassword` true. The temporary password's `NotBlank`/`Length(6, 100)` stay on the form field (the console command never had them).
  - `UpdateAccountCommand` (public `int $id`, `?string $role`, `string $fullName`, `string $username`, `bool $active`; same constraints) → runs every `AccountRoleChangeGuardInterface` (all reasons collected), refuses a taken username, logs "Updated account: <username>", returns the account array.
  - `ToggleAccountActiveCommand(int $id, string $actingUsername)` → `OwnAccountException('You cannot deactivate your own account.')`; on reactivation every `AccountReactivationGuardInterface`; logs "Activated account: …" / "Deactivated account: …"; returns the account array.
  - `ResetAccountPasswordCommand(int $id, string $actingUsername)` → `OwnAccountException('Use "Change password" to change your own password.')`; new temporary password, `mustChangePassword` true, logs "Reset password: …"; returns `['fullName', 'username', 'password']`.
  - `SearchAccountsCommand(?string $query, ?string $role, ?bool $active, int $page, int $perPage)` → `['accounts' => list<account array>, 'total' => int, 'schoolNames' => array<int, string>]`.
  - `GetAccountCommand(int $id)` → the account array plus `learnerLrn` (`?string`); `AccountNotFoundException` → the controller answers 404.
  - `AccountTransformer::toArray(User $user): array{id: int, username: string, fullName: string, role: ?string, roleLabel: string, initials: string, active: bool, mustChangePassword: bool, lastLoginAt: ?\DateTimeImmutable, photoPath: ?string}` (keys the templates read today).
  - Templates use `constant('App\\Access\\Domain\\Model\\User::ROLE_…')`.

- [ ] **Step 1: Move/split the tests** (assertions unchanged) and add `testDuplicateUsernameIsRefused` (create and edit). Unit tests per handler for each refusal and the happy path (stubs for repository, hasher, generator, guards, logger).
- [ ] **Step 2: Implement** commands, handlers, transformer, controller, form, legacy `TeacherSchoolController`, templates. Field errors: `UsernameTakenException` → Username; `AccountRoleChangeRefusedException` reasons → Role; other domain failures → flash `error` as today.

### Task 5: Create-user console command

**Files:**
- Move: `src/Command/CreateUserCommand.php` → `src/Access/Presentation/Console/CreateUserConsoleCommand.php` (same name `app:user:create`, arguments, option, messages and exit codes); dispatches `CreateAccountCommand`
- Test: `tests/Access/Integration/Presentation/CreateUserConsoleCommandTest.php`

- [ ] **Step 1: Write the tests** (`CommandTester`): `testCreatesAnAccountThatMustChangeItsPassword` (success text "Created teacher \"ana\". They will be asked to set a new password when they first sign in."), `testUnknownRoleIsRefused` ("Role must be one of: admin, principal, teacher, student."), `testExistingUsernameIsRefused` ("User \"ana\" already exists."), `testInvalidUsernameShowsTheValidationError` ("username: Use only letters, numbers, dots, dashes and underscores.").
- [ ] **Step 2: Implement**; validation failures print `<property>: <message>` per violation as today.

### Task 6: Profile photo

**Files:**
- Move: `src/Form/ImageUploadFormType.php` → `src/Core/Presentation/Form/ImageUploadFormType.php`; `templates/profile/_photo_form.html.twig` → `src/Core/Presentation/templates/_photo_form.html.twig` (callers: `profile/_layout.html.twig`, `learners/show.html.twig`, the learner controller)
- Create: `src/Access/Presentation/Controller/ProfilePhotoController.php` (routes `profile_photo`, `profile_photo_remove` with today's paths and CSRF id, `photoForm()` fragment; `profile/_layout.html.twig` renders `App\\Access\\Presentation\\Controller\\ProfilePhotoController::photoForm`), `src/Access/Application/UseCase/Command/{ChangeProfilePhotoCommand,RemoveProfilePhotoCommand}.php` and handlers
- Modify: `src/Controller/ProfileController.php` (photo actions and `back()` use removed; the PDS parts stay)
- Move tests: the five photo tests of `tests/Controller/ProfileControllerTest.php` → `tests/Access/Functional/ProfilePhotoControllerTest.php`

**Interfaces:**
- Produces: `ChangeProfilePhotoCommand(int $userId, string $sourcePath)` → stores through `ImageProcessorInterface::storePhoto`, logs "Changed profile photo" (an `ImageProcessingException` becomes the flash error as today); `RemoveProfilePhotoCommand(int $userId)` → removes the file, clears the path, logs "Removed profile photo".

- [ ] **Step 1: Move the tests** (assertions unchanged); handler unit tests.
- [ ] **Step 2: Implement.**

### Task 7: Baselines, docs, comments, deprecations

- [ ] **Step 1:** Record the PHPStan baseline total; delete `reports/phpstan/.cache`; prune entries for deleted files; regenerate; check the rules in Global Constraints. Regenerate the deptrac baseline (with `--cache-file`); it must stay empty.
- [ ] **Step 2: Docs.** `CLAUDE.md`: Access module (where accounts, sign-in and profile photo live; hooks for lower modules and where their legacy implementations are; `TransactionLockInterface` and `BusController` usage; `User` is `App\Access\Domain\Model\User`; domain terms line for `app:user:create` unchanged). `AGENTS.md` only if a command changed.
- [ ] **Step 3: Comment sweep and deprecation check** over every touched file; `debug:container --deprecations` clean.

### Task 8: Verification

- [ ] **Step 1: Scoped run:** `make bash-exec CMD='bash docker/test/run-feature-tests.sh tests/Core tests/Access tests/Controller tests/Service'`; fix and re-run only the failures.
- [ ] **Step 2: Full runs on the final code, one at a time:** `make test all` (8/8), `make test-feature` (coverage 100.00% of `src/Core` + `src/Access`), `make regression-test` (5).
- [ ] **Step 3:** `doctrine:schema:validate` in sync, `doctrine:migrations:diff` empty, route list identical to before (names, paths, methods).
- [ ] **Step 4: Fresh whole-change review** (most capable model, read-only); fix Critical/Important with a failing test first; full runs again.
- [ ] **Step 5: Ask the owner before the live-browser check.** If approved: the Phase 2a headless develop-vs-branch comparison plus sign-in, first-sign-in password change, admin create/edit/deactivate/reactivate/reset, and profile photo upload/remove on both.
- [ ] **Step 6: Report** (timeline, changes, rulings, deferred minors, mistakes with proposed notes) and the proposed commit message; commit only after approval:

```
Move accounts and sign-in into the Access module

Move the user account, sign-in, password change, account administration,
the create-user command and the profile photo into an Access module whose
actions all go through the bus. Rules owned by schools, plans and learners
reach it through hooks those parts implement. Core gains locks held until
a use case commits and a controller base that keeps unexpected failures
as error pages. Pages, messages and URLs are unchanged.
```
