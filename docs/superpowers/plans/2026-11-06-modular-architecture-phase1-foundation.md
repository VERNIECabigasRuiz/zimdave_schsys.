# Modular architecture, Phase 1 (Foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lay the foundation the module moves need: Docker tooling, quality gates with baselines, the `Core` bus, the Vite/Vue/Tailwind toolchain, a demo seeder and browser smoke tests, without changing anything a user sees.

**Architecture:** `src/Core/` gets the four DDD layers with the bus contract (`Application/Bus`), domain exceptions (`Domain/Exception`) and a Messenger-based implementation (`Infrastructure/Bus`). Static checks (PHPStan 9, deptrac, phpcs PSR-12) run on all code, with today's code frozen in baselines or left out of scope until it moves. AssetMapper is replaced by Vite (pentatrion), and existing Stimulus controllers and CSS are bundled unchanged.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Symfony Messenger, PHPUnit 13, PHPStan 2.3, deptrac 4.7, PHP_CodeSniffer 4.0, Symfony Panther 2.4, Vite 8.3 + vite-plugin-symfony 8.2, Vue 3.5, Tailwind 4.3, Vitest 5.1, ESLint 10, Stylelint 18, pnpm, Node 24.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (sections 3, 5, 6, 7, 8, 9 and 11, Phase 1).

**Branch:** `feature/modular-architecture` (created from `develop`). It already carries the uncommitted phpMyAdmin service (`docker-compose.yml`) and its `AGENTS.md` line; they go into this phase's commit.

## Timeline

Fill in as each task starts and ends (team notes: record start/end and duration per task).

| Task | Started | Ended | Duration |
|---|---|---|---|
| 1. Docker base image tooling | 17:27:53 | 17:36:51 | 9m |
| 2. Quality tools, suites and baselines | 17:36:59 | 17:44:59 | 8m |
| 3. Core bus | 17:44:59 | 17:51:43 | 7m |
| 4. Vite, Vue and Tailwind toolchain | 17:51:51 | 18:05:55 | 14m |
| 5. Demo seeder | 18:05:55 | 18:13:28 | 8m |
| 6. Browser smoke tests (Panther) | 18:13:28 | 18:16:51 | 3m |
| 7. Docs and skills | 18:16:51 | 18:17:52 | 1m |
| 8. Comment clean-up and deprecation check | 18:17:52 | 18:18:29 | 1m |
| 9. Verification | 18:18:29 | 19:12:52 | 54m |

## Global Constraints

- Structure only, no behaviour change: every page, URL, route name, form, PDF and spreadsheet works as today.
- Table and column names stay the same; `doctrine:migrations:diff` must stay empty after this phase.
- Tooling runs in Docker only (`make bash-exec CMD='...'`, `make test`, `make test-feature`, `make regression-test`). Never `make run`/`make stop` (they prune machine-wide); start the stack with `docker compose up --build -d`.
- In Git Bash, prefix `docker exec` commands that take container paths with `MSYS_NO_PATHCONV=1`; never pass `/dev/null` as an argument.
- New packages go in through `composer require` so Flex recipes run (`AGENTS.md`). Don't hand-edit `config/bundles.php`.
- PHP attributes, autowiring, constructor promotion; `readonly` for DTOs/value objects/attributes only, never for services (lazy proxies).
- PHPStan level 9, phpcs PSR-12, `phpunit.dist.xml` keeps failing on deprecations, notices and warnings.
- Coverage gate 100% for `src/Core` (PHP) and for module/Core frontend code (JS).
- Vue: Options API only. Node 24, pnpm through Corepack.
- New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first when an exception is expected) and the existing helpers (`SchoolFixturesTrait`, `AdminTestCase`).
- Write each task's tests during the task; **don't run tests per task**. All test runs happen once, in Task 9. Running a tool to generate a baseline or install packages is setup, not a test run.
- Run every tool and test command one at a time, never in parallel. The test image's entrypoint runs its checks one after another (ams-core-02's runs them in parallel; don't copy that part).
- Comments: short, plain, only where the code isn't clear, and no references to plans, tasks or tools used to write the code. No Claude/Anthropic mention anywhere.
- One commit at the end, after explicit approval (Subagent-Driven mode: per-task commits with approval given afterwards, attribution footers stripped).

## Review Focus

1. **Login and every form still post after the Vite switch.** `csrf_protection_controller.js` is a side-effect module (it exports a string, not a controller) and CSRF is stateless for `submit`, `authenticate` and `logout`. If it's registered as a Stimulus controller instead of imported, Stimulus throws and the CSRF cookie is never set. Pinned by the Panther login flow in Task 6 and the existing `SecurityControllerTest`.
2. **Turbo stays on and prefetch stays off.** A user expects the same quick page swaps and no consumed one-time passwords. Pinned in Task 6 (`window.Turbo` is defined, `meta[name=turbo-prefetch][content=false]` is present).
3. **Tailwind must not restyle any current page.** Templates already use class names like `hidden` and `muted`. Pinned in Task 4: no preflight, every utility prefixed `tw:`, and a build check that the utilities layer is empty while no template uses `tw:`.
4. **Running the seeder on a database that already has demo data.** Expect a clear message, not a duplicate-key crash. Pinned by `DemoFixturesTest::testRefusesToLoadTwice` in Task 5.
5. **A command with no handler, or a handler that crashes, reaching a controller.** Expect a failed `Result` (`INTERNAL_ERROR`), not an uncaught exception. Pinned by `BusIntegrationTest::testUnhandledCommandFails` and `::testUnexpectedExceptionBecomesInternalError` in Task 3.
6. **Functional tests on a fresh checkout without built assets.** `vite_entry_*_tags()` throws when `public/build` is missing. Pinned in Tasks 2 and 6: the feature and regression scripts build assets first.

## Out of scope for Phase 1 (from the spec's phase list)

- Moving any module code, including the non-bus `Core` items in the spec's table (`ActivityLog`, `SchoolClock`, image processing, PDF/XLSX/storage adapters, shared Twig pieces). Those move at the start of Phase 2, before `Access`.
- Twig namespaces per module. They're registered when the first module moves.
- Vitest tests for the existing Stimulus controllers, plus ESLint/Stylelint on the legacy `assets/` files. Each controller gets its tests and lint when it moves into its module's `Presentation/assets/`, as section 8 excludes legacy code until it moves; `app.css`/`admin.css` join in Phase 5 at the latest.

---

### Task 1: Docker base image tooling

**Files:**
- Modify: `docker/Dockerfile.base`, `docker/Dockerfile:1`, `docker/config/php.ini`, `make-config.yaml` (`docker.base.image.run.name`), `.gitignore`

**Interfaces:**
- Produces: base image `vernie/zimdave-schsys:1.1` with `pcov` (disabled by default), `node` 24, `pnpm` (Corepack) and `google-chrome-stable`. Later tasks run `node`, `pnpm`, `google-chrome` and `php -d pcov.enabled=1` inside `web` and the test image.

- [ ] **Step 1: Extend `docker/Dockerfile.base`** with three blocks taken from ams-core-02's `docker/Dockerfile.base` (`P:/ProgramData/Projects/Ardag/dev/ams-core-02/docker/Dockerfile.base`): `pecl install pcov && docker-php-ext-enable pcov`; NodeSource `setup_24.x` + `apt-get install -y nodejs` + `corepack enable`; the Google Chrome apt repo + `google-chrome-stable`. Add `gnupg` to the first `apt-get install` list (needed for the Chrome key). Keep comments plain (no emoji), in the file's existing style.
- [ ] **Step 2: Add `pcov.enabled = 0` to `docker/config/php.ini`** with a one-line comment ("Coverage runs turn pcov on with -d pcov.enabled=1"), so php-fpm and normal test runs don't pay for it.
- [ ] **Step 3: Bump the image tag** to `vernie/zimdave-schsys:1.1` in `make-config.yaml` and in `docker/Dockerfile`'s `FROM`. The new tag makes `ensure-baseimg` build it instead of reusing `1.0`.
- [ ] **Step 4: Add to `.gitignore`**: `/node_modules/`, `/public/build/`, `/reports/`. Run `git check-ignore -v` on each to confirm (the global gitignore counts too).
- [ ] **Step 5: Build and start.** Run `make generate-baseimg`, then `docker compose up --build -d`. Read the full output (don't pipe it through `tail`) and confirm `web` reaches `healthy` in `docker ps`.
- [ ] **Step 6: Check the tools** in one call: `make bash-exec CMD='node -v && pnpm -v && google-chrome --version && php -m | grep -i pcov && php -i | grep pcov.enabled'`. Expected: `v24.*`, a pnpm version, a Chrome version, `pcov`, `pcov.enabled => Off`. Write the pnpm version down for Task 4's `packageManager`.
- [ ] **Step 7: Remove the old image** by name only: `docker rmi vernie/zimdave-schsys:1.0` (never prune machine-wide).

### Task 2: Quality tools, suites and baselines

**Files:**
- Modify: `composer.json` (via `composer require --dev`), `phpunit.dist.xml`, `make-config.yaml` (`tests.*`), `AGENTS.md` (Code style)
- Create: `config/phpstan.neon`, `config/phpstan-baseline.neon` (generated), `config/deptrac.yaml`, `config/deptrac.baseline.yaml` (generated), `config/phpcs.xml`, `tests/object-manager.php`, `docker/Dockerfile.test`, `docker/entrypoint_test.sh`, `docker/test/run-feature-tests.sh`

**Interfaces:**
- Consumes: Task 1's image `vernie/zimdave-schsys:1.1`.
- Produces: test suites `unit`, `integration`, `functional`, `browser`, `legacy`; `make test [composer-audit|phpstan|deptrac|phpcs|eslint|stylelint|phpunit|vitest|all]` (the disposable test image, no database); `make test-feature` (unit + integration + functional + legacy in `web`, with the 100% gate on `src/Core`); deptrac layer names `Core_Domain`, `Core_Application`, `Core_Infrastructure`, `Core_Presentation`, `Core_BusAttributes`, `Kernel`, `Legacy`, `DoctrineMapping`, `ValidatorConstraints`, `Framework`, `Vendor`.

- [ ] **Step 1: Install the dev tools** in one call: `make bash-exec CMD='composer require --dev phpstan/phpstan:^2.3 phpstan/phpstan-symfony:^2.1 phpstan/phpstan-doctrine:^2.1 phpstan/phpstan-phpunit:^2.1 phpstan/phpstan-deprecation-rules:^2.0 deptrac/deptrac:^4.7 squizlabs/php_codesniffer:^4.0 rregeer/phpunit-coverage-check:^0.3.1'`. Read the full output; confirm `composer.json` and `composer.lock` changed and no recipe touched other files unexpectedly (`git status`).
- [ ] **Step 2: Split `phpunit.dist.xml` into suites**, keeping every existing attribute and the `<source>` deprecation triggers:
  - `unit`: `<directory>tests/*/Unit</directory>`
  - `integration`: `<directory>tests/*/Integration</directory>`
  - `functional`: `<directory>tests/*/Functional</directory>`
  - `browser`: `<directory>tests/Browser</directory>`
  - `legacy`: today's folders listed one by one (`tests/Attendance`, `tests/CardSlip`, `tests/Controller`, `tests/Data`, `tests/Entity`, `tests/Grading`, `tests/LessonPlan`, `tests/Nutrition`, `tests/Repository`, `tests/Security`, `tests/Service`, `tests/ServiceRecord`, `tests/StaffId`) plus `<file>tests/KernelTimezoneTest.php</file>`. Each module plan removes its folders from here as it moves; Phase 5 deletes the suite.
  - `<source><include>`: only `src/Core` (each module plan adds its folder).
  - `<php>`: add `<server name="PANTHER_APP_ENV" value="test" force="true" />`, `<server name="PANTHER_NO_SANDBOX" value="1" />`, `<server name="PANTHER_CHROME_ARGUMENTS" value="--disable-dev-shm-usage" />` (Chrome runs as root in the container; Panther's default `panther` env isn't one of the kernel's allowed envs).
- [ ] **Step 3: Write `tests/object-manager.php`** (phpstan-doctrine's loader, as in its README): load `vendor/autoload.php`, `(new Dotenv())->bootEnv(dirname(__DIR__).'/.env')`, boot `new Kernel('dev', true)`, return `$kernel->getContainer()->get('doctrine')->getManager()`.
- [ ] **Step 4: Write `config/phpstan.neon`**: `level: 9`, `tmpDir: ../reports/phpstan/.cache`, `paths: [../src, ../tests]`, `excludePaths: [../tests/bootstrap.php]`; `includes` for each extension's `extension.neon` and `rules.neon` (symfony, doctrine, phpunit) plus `vendor/phpstan/phpstan-deprecation-rules/rules.neon` and `phpstan-baseline.neon`; `symfony.containerXmlPath: ../var/cache/dev/App_KernelDevDebugContainer.xml`; `doctrine.objectManagerLoader: ../tests/object-manager.php`.
- [ ] **Step 5: Write `config/deptrac.yaml`** (paths `../src`), following the shape of ams-core-02's `config/deptrac.yaml`:
  - Layers (`classLike` regex collectors): `Core_Domain` `^App\\Core\\Domain\\`, `Core_Application` `^App\\Core\\Application\\`, `Core_Infrastructure` `^App\\Core\\Infrastructure\\(?!Bus\\Attributes\\)`, `Core_BusAttributes` `^App\\Core\\Infrastructure\\Bus\\Attributes\\`, `Core_Presentation` `^App\\Core\\Presentation\\`, `Kernel` `^App\\Kernel$`, `Legacy` `^App\\(?!Core\\|Kernel$)`, `DoctrineMapping` `^Doctrine\\(ORM\\Mapping|Common\\Collections)\\`, `ValidatorConstraints` `^Symfony\\Component\\Validator\\Constraints\\`, `Framework` `^(Symfony\\(?!Component\\Validator\\Constraints\\)|Doctrine\\(?!ORM\\Mapping\\|Common\\Collections\\)|Psr\\|Twig\\|Monolog\\)`, `Vendor` `^(Dompdf|PhpOffice|chillerlan|Faker)\\`.
  - Ruleset: `Core_Domain: [DoctrineMapping]`; `Core_Application: [Core_Domain, Core_BusAttributes, ValidatorConstraints]`; `Core_Infrastructure: [Core_Domain, Core_Application, Core_BusAttributes, DoctrineMapping, ValidatorConstraints, Framework, Vendor]`; `Core_Presentation: [Core_Application, Core_Domain, ValidatorConstraints, Framework]`; `Core_BusAttributes: ~`; `Kernel` and `Legacy`: every other layer; the vendor layers: `~`.
  - A short header comment naming the per-module pattern later phases follow (`<Module>_Domain`, `<Module>_Application`, ...; a module's Domain may use the Domain of modules above it; anything else goes through `<Module>/Application/Api`).
  - `imports: [deptrac.baseline.yaml]`.
- [ ] **Step 6: Write `config/phpcs.xml`**: `PSR12`, `basepath .`, `colors`, `extensions php`, files `../src/Core`, `../src/DataFixtures`, `../tests/Core`, `../tests/DataFixtures`, `../tests/Browser`. Legacy code is left out; each module plan adds its folders and runs `phpcbf` on them as they move.
- [ ] **Step 7: Generate the baselines** (setup, not a test run), one at a time, after warming the dev container (`make bash-exec CMD='php bin/console cache:warmup --env=dev'`):
  - `make bash-exec CMD='vendor/bin/phpstan analyse -c config/phpstan.neon --memory-limit=1G --generate-baseline config/phpstan-baseline.neon'`
  - `make bash-exec CMD='vendor/bin/deptrac analyse --config-file=config/deptrac.yaml --formatter=baseline --output=config/deptrac.baseline.yaml'`

  Check both files aren't empty (deptrac's can legitimately be `skip_violations: {}` since no module exists yet). Generate them **before** Tasks 3 to 6 add code, so new code never lands in a baseline.
- [ ] **Step 8: Write `docker/Dockerfile.test`** (`FROM vernie/zimdave-schsys:1.1`, `WORKDIR /app`, copy `docker/entrypoint_test.sh`, `ENTRYPOINT`), and **`docker/entrypoint_test.sh`**, modelled on ams-core-02's `docker/entrypoint_test.sh` with these differences:
  - Checks run **sequentially** in the order composer-audit, phpstan, deptrac, phpcs, eslint, stylelint, phpunit, vitest. Each check's output is shown as it runs, then a summary prints with ✅/❌ and seconds per check. Exit 1 if any check failed (static checks too).
  - Before checks: `export DATABASE_URL='mysql://root:password@127.0.0.1:3306/zimdave_schsys?serverVersion=8.4.0&charset=utf8mb4'` (no connection is made; it fixes the platform for Doctrine metadata), `composer install -n`, `pnpm install --frozen-lockfile` (when `package.json` exists), `php bin/console cache:warmup --env=dev`, and `mkdir -p` each `reports/<check>` folder.
  - `phpunit` runs `php bin/phpunit --testsuite unit` (+ `--filter "$FILTER"` when set), no coverage (the gate needs the database suites, so it lives in `make test-feature`).
  - `phpstan`: `-c config/phpstan.neon --memory-limit=1G --no-progress`. `deptrac`: `--config-file=config/deptrac.yaml --cache-file=reports/deptrac/.cache --report-uncovered`. `phpcs`: `--standard=config/phpcs.xml`. `composer audit --abandoned=ignore -n`.
  - `eslint`, `stylelint`, `vitest`: the commands from Task 4 Step 9; until Task 4 lands, the type exists and prints "not set up yet".
  - Usage line and unknown-type error as in ams-core-02; `all` runs everything once. Accept and ignore the Base Makefile's `--no-phpunit`, `--no-parallel` and `--help` (print usage) flags.
- [ ] **Step 9: Write `docker/test/run-feature-tests.sh`** (runs inside `web`): build assets when `public/build/.vite/entrypoints.json` is missing (`pnpm install --frozen-lockfile && pnpm build`, skipped while `package.json` doesn't exist); `mkdir -p reports/phpunit`; when `$TEST_TARGET`/args are given, run `php bin/phpunit "$@"` without coverage; otherwise run `php -d pcov.enabled=1 bin/phpunit --testsuite unit,integration,functional,legacy --coverage-clover reports/phpunit/coverage.xml --log-junit reports/phpunit/junit.xml` then `vendor/bin/coverage-check reports/phpunit/coverage.xml 100`. `set -e` so either failure exits non-zero.
- [ ] **Step 10: Update `make-config.yaml` `tests:`**: `phpunit.from: vernie/zimdave-schsys:1.1`, `phpunit.path: docker/Dockerfile.test`, `phpunit.image: vernie/local-test:zimdave-schsys`, `phpunit.test_suite: unit`, `phpunit.test_options: '--testdox --colors=always'`; `feature.test: [make bash-exec CMD='bash docker/test/run-feature-tests.sh $(TEST_OPTIONS) $(TEST_TARGET)']` with `feature.test_options` empty (the testdox flag would otherwise disable the coverage path). Task 6 fills `regression`.
- [ ] **Step 11: Update `AGENTS.md` "Code style"**: phpcs PSR-12 (`config/phpcs.xml`), checked with `make test phpcs`, fixed with `make bash-exec CMD='vendor/bin/phpcbf --standard=config/phpcs.xml'`; replace the php-cs-fixer line (owner's decision, spec section 3).

### Task 3: Core bus

**Files:**
- Create (`src/Core/`):
  - `Application/Bus/`: `CommandBusInterface.php`, `UseCaseBusInterface.php`, `QueryBusInterface.php`, `UseCase.php`, `Query.php`, `Loggable.php`, `UseCaseHandlerInterface.php`, `QueryHandlerInterface.php`, `Result.php`
  - `Domain/Exception/`: `DomainException.php`, `GenericErrorCode.php`
  - `Infrastructure/Bus/`: `AbstractMessengerCommandBus.php`, `MessengerUseCaseBus.php`, `MessengerQueryBus.php`, `BusHandlerAutoconfiguration.php`, `Attributes/AsUseCaseHandler.php`, `Attributes/AsQueryHandler.php`, `Attributes/Cacheable.php`, `Middleware/LoggingMiddleware.php`, `Middleware/ExceptionHandlingMiddleware.php`, `Middleware/CachingMiddleware.php`
- Modify: `src/Kernel.php` (add `build()`), `config/packages/messenger.yaml`, `config/packages/cache.yaml`, `config/packages/monolog.yaml` (`bus` channel), `config/services.yaml` (`when@test` fixture services)
- Test: `tests/Core/Unit/Application/Bus/ResultTest.php`, `tests/Core/Unit/Domain/Exception/DomainExceptionTest.php`, `tests/Core/Unit/Infrastructure/Bus/BusHandlerAutoconfigurationTest.php`, `tests/Core/Unit/Infrastructure/Bus/AbstractMessengerCommandBusTest.php`, `tests/Core/Unit/Infrastructure/Bus/Middleware/{LoggingMiddlewareTest,ExceptionHandlingMiddlewareTest,CachingMiddlewareTest}.php`, `tests/Core/Integration/Bus/BusIntegrationTest.php`, fixtures in `tests/Core/Integration/Bus/Fixture/`

**Interfaces:**
- Produces (namespace `App\Core\...`; every later phase uses these exact names):
  - `Application\Bus\CommandBusInterface::handle(UseCase|Query $command): Result`; `UseCaseBusInterface` and `QueryBusInterface` extend it with no methods.
  - `Application\Bus\Loggable::toLogArray(): array` (`@return array<string, mixed>`); `UseCase` and `Query` extend `Loggable`, no methods.
  - `Application\Bus\UseCaseHandlerInterface` (`@template TUseCase of UseCase`): `__invoke(UseCase $command): Result` (`@param TUseCase $command`). `QueryHandlerInterface` the same with `Query`.
  - `Application\Bus\Result`: `final readonly`, private constructor; `success(mixed $data = null): self`, `failure(\Throwable $exception, string $errorCode): self`, `isSuccess(): bool`, `isFailure(): bool`, `data(): mixed`, `exception(): ?\Throwable`, `errorCode(): ?string`. Copy ams-core-02's `src/AMS/Application/Bus/Result.php` verbatim apart from the namespace.
  - `Domain\Exception\DomainException`: abstract, extends `\Exception`; `__construct(string $message = '', string $code = '', ?\Throwable $previous = null, array $data = [])`, `errorCode(): string`, `data(): array`. Copy ams-core-02's `src/AMS/Domain/Exception/DomainException.php` apart from the namespace.
  - `Domain\Exception\GenericErrorCode`: string enum, `InternalError = 'INTERNAL_ERROR'`, `ValidationFailed = 'VALIDATION_FAILED'`.
  - `Infrastructure\Bus\Attributes\AsUseCaseHandler(string $useCaseClass)`, `AsQueryHandler(string $queryClass)`, `Cacheable(string $namespace, int $ttl = 300)`: `#[\Attribute(\Attribute::TARGET_CLASS)] final readonly`, as in ams-core-02.
  - `Infrastructure\Bus\MessengerUseCaseBus` (implements `UseCaseBusInterface`) and `MessengerQueryBus` (implements `QueryBusInterface`): constructors take `#[Target('usecase.bus')]` / `#[Target('query.bus')] MessageBusInterface $bus` and pass it to `AbstractMessengerCommandBus::__construct(MessageBusInterface $bus)`. Inject them by interface: `UseCaseBusInterface $useCaseBus`, `QueryBusInterface $queryBus`.
  - `Infrastructure\Bus\BusHandlerAutoconfiguration::register(ContainerBuilder $container): void`, called from `Kernel::build()`.
  - Messenger buses `usecase.bus`, `query.bus`, `event.bus`; cache pool `cache.bus`; monolog channel `bus`.

- [ ] **Step 1: Write the unit tests.**
  - `ResultTest`: `testSuccessCarriesData` (`Result::success(['a' => 1])`: `isSuccess()` true, `isFailure()` false, `data()` `['a' => 1]`, `exception()` and `errorCode()` null); `testSuccessDefaultsToNullData`; `testFailureCarriesExceptionAndCode` (`Result::failure($e, 'X_CODE')`: `isFailure()` true, `exception()` same instance, `errorCode()` `'X_CODE'`, `data()` null).
  - `DomainExceptionTest` (an anonymous subclass): `testCarriesCodeMessageDataAndPrevious` (`errorCode()` `'SOME_CODE'`, `getMessage()`, `data()` `['id' => 5]`, `getPrevious()`, `getCode()` `0`).
  - `BusHandlerAutoconfigurationTest`: build a `ContainerBuilder`, call `register()`, register an autoconfigured definition for a fixture class carrying `#[AsUseCaseHandler(SomeCommand::class)]`, run `AttributeAutoconfigurationPass` then `ResolveInstanceofConditionalsPass`. Assert the tag `messenger.message_handler` equals `[['bus' => 'usecase.bus', 'handles' => SomeCommand::class]]`. A second test does the same for `#[AsQueryHandler]` and `query.bus`.
  - `AbstractMessengerCommandBusTest` (a `MessageBusInterface` stub returning prepared envelopes): `testReturnsTheHandledResult`; `testSentWithoutHandlingIsSuccessWithoutData` (no `HandledStamp`: `isSuccess()`, `data()` null); `testMoreThanOneHandlerIsInternalError` (two `HandledStamp`s: failure, `INTERNAL_ERROR`, `\LogicException` naming the command class); `testNonResultIsInternalError` (`HandledStamp('x', 'h')`: failure, `INTERNAL_ERROR`).
  - `LoggingMiddlewareTest` (Monolog `Logger` + `TestHandler`, `StackMiddleware` with a small inline next middleware that adds a `HandledStamp`): `testLogsDispatchWithLogArray` (an info record `Dispatching <class>` whose context equals `toLogArray()`); `testLogsSuccess` (info `Handled <class> successfully`); `testLogsFailureWithCode` (warning `Handled <class> with failure`, context `['errorCode' => 'X_CODE']`).
  - `ExceptionHandlingMiddlewareTest`: `testPassesResultThrough`; `testDomainExceptionBecomesFailureWithItsCode` (thrown directly, then wrapped in `HandlerFailedException`: both give its `errorCode()`, and `exception()` is the domain exception, not the wrapper); `testValidationFailureBecomesValidationFailed` (`ValidationFailedException`: `VALIDATION_FAILED`); `testUnexpectedExceptionBecomesInternalErrorAndIsLogged` (`\RuntimeException`: `INTERNAL_ERROR`, one error record naming the command class).
  - `CachingMiddlewareTest` (`TagAwareAdapter(new ArrayAdapter())`): `testPassesThroughWithoutCacheable`; `testMissCallsNextAndStores` then `testHitSkipsNext` (next not called the second time; same data); `testNamespacePlaceholderUsesPropertyValue` (`#[Cacheable('section-{sectionId}')]` with `sectionId = 7`: invalidating tag `section-7` makes the next call a miss); `testFailureIsNotCached`; `testObjectDataIsNotCached` (a warning is logged, the next call is a miss); `testCacheErrorFailsOpen` (an adapter whose `getItem()` throws: next is still called, the result is returned, a warning is logged).
- [ ] **Step 2: Write the integration test and fixtures.** Fixtures in `tests/Core/Integration/Bus/Fixture/` (namespace `App\Tests\Core\Integration\Bus\Fixture`): `PingCommand implements UseCase` (`#[Assert\NotBlank] public string $label`) with handler returning `Result::success(['label' => $label])`; `RefusePingCommand` whose handler throws `PingRefusedException extends DomainException` with code `PING_REFUSED`; `CrashPingCommand` whose handler throws `\RuntimeException`; `LogThenFailCommand` whose handler persists `new ActivityLog('bus-test', 'rolled back')`, flushes, then throws `\RuntimeException`; `CountPingsQuery implements Query` with `#[Cacheable('pings')]`, whose handler increments a static counter and returns it; `UnhandledCommand implements UseCase` with no handler. Register the folder in `config/services.yaml` under `when@test` (`App\Tests\Core\Integration\Bus\Fixture\:` with `resource: '../tests/Core/Integration/Bus/Fixture/'`) so the attributes autoconfigure them.

  `BusIntegrationTest extends KernelTestCase`, getting `UseCaseBusInterface` and `QueryBusInterface` from the container:
  - `testHandlesUseCase` (success, `data()` `['label' => 'hi']`)
  - `testDomainExceptionBecomesFailure` (`PING_REFUSED`)
  - `testUnexpectedExceptionBecomesInternalError`
  - `testInvalidCommandIsRejectedBeforeTheHandler` (`label = ''`: `VALIDATION_FAILED`)
  - `testFailedUseCaseRollsBack` (no `ActivityLog` with username `bus-test` afterwards; delete leftovers in `setUp`)
  - `testCachedQueryRunsHandlerOnce` (dispatch twice, the counter stays `1`)
  - `testUnhandledCommandFails` (`INTERNAL_ERROR`)
  - `testQueryBusDoesNotRunUseCases` (dispatch `PingCommand` on the query bus: `INTERNAL_ERROR`)
- [ ] **Step 3: Write the `Application/Bus` and `Domain/Exception` classes** with the signatures in Interfaces, copying ams-core-02's files (`P:/ProgramData/Projects/Ardag/dev/ams-core-02/src/AMS/Application/Bus/`, `src/AMS/Domain/Exception/`). Add `declare(strict_types=1);` to every new file, as ams-core-02 does.
- [ ] **Step 4: Write the attributes and `BusHandlerAutoconfiguration`.** `register()` calls `$container->registerAttributeForAutoconfiguration()` for each handler attribute, adding the tag `messenger.message_handler` with `bus` and `handles` (see the Step 1 test). Add `protected function build(ContainerBuilder $container): void { BusHandlerAutoconfiguration::register($container); }` to `src/Kernel.php`.
- [ ] **Step 5: Write `AbstractMessengerCommandBus::handle()`.** Dispatch, collect `$envelope->all(HandledStamp::class)`. None: `Result::success()`. More than one: failure `INTERNAL_ERROR` with a `\LogicException`. One: return its result if it's a `Result`, else failure `INTERNAL_ERROR`. Not `readonly` (services may become lazy).
- [ ] **Step 6: Write the middleware** (each `implements MiddlewareInterface`, `final`, promoted `private readonly` dependencies):
  - `LoggingMiddleware(#[WithMonologChannel('bus')] on the class, LoggerInterface $logger)`: log before and after as tested. Read the outcome from the last `HandledStamp`'s `Result`.
  - `ExceptionHandlingMiddleware(LoggerInterface $logger)` (also on the `bus` channel): wrap `$stack->next()->handle()`. Unwrap `HandlerFailedException` to its first wrapped exception through `getWrappedExceptions()` (not the deprecated `getNestedExceptions()`). Map as tested, and return `$envelope->with(new HandledStamp($result, self::class))`.
  - `CachingMiddleware(#[Target('cache.bus')] TagAwareAdapterInterface $cache, LoggerInterface $logger)`: only for `Query` messages with `#[Cacheable]`. Key `'bus.'.sha1($message::class.serialize($message))`. Tag is the namespace with each `{property}` replaced by that public property's value. Store `['data' => $data]` only for successful results whose data holds no objects; `expiresAfter($ttl)`. Any cache `\Throwable` logs a warning and dispatches uncached (ams-core-02's `CachingMiddleware` is the reference behaviour).
- [ ] **Step 7: Configure.**
  - `messenger.yaml`: keep `default_bus: messenger.bus.default` and the transports/routing. Add `usecase.bus` (middleware: `LoggingMiddleware`, `ExceptionHandlingMiddleware`, `doctrine_transaction`, `validation`), `query.bus` (`LoggingMiddleware`, `ExceptionHandlingMiddleware`, `validation`, `CachingMiddleware`) and `event.bus` (`default_middleware: { enabled: true, allow_no_handlers: true }`).
  - `cache.yaml`: pool `cache.bus` (`adapter: cache.app`, `tags: true`), and `when@test` with `adapter: cache.adapter.array` so runs never see stale entries.
  - `monolog.yaml`: add `bus` to `channels`.

### Task 4: Vite, Vue and Tailwind toolchain

**Files:**
- Remove (through `composer remove`): `symfony/asset-mapper` with its recipe files (`config/packages/asset_mapper.yaml`, `importmap.php`, `assets/vendor/`)
- Create: `package.json`, `pnpm-lock.yaml` (generated), `.npmrc`, `vite.config.js`, `config/vitest.config.js`, `config/eslint.config.mjs`, `config/stylelint.config.cjs`, `assets/bootstrap.js`, `src/Core/Presentation/assets/styles/tailwind.css`, `src/Core/Presentation/assets/vue/createVueComponentResolver.js`, `tests/Core/Javascript/createVueComponentResolver.test.js`, `tests/Core/Javascript/Fixture/Greeting.vue`
- Delete: `assets/stimulus_bootstrap.js` (replaced by `assets/bootstrap.js`)
- Modify: `composer.json` (packages, `auto-scripts`, `extra.symfony.allow-contrib`), `assets/app.js`, `assets/controllers.json`, `templates/base.html.twig`, `docker/entrypoint.sh`, `docker/entrypoint_test.sh` (eslint/stylelint/vitest), `make-config.yaml` (`assets`)

**Interfaces:**
- Consumes: Task 1's Node/pnpm; Task 2's test entrypoint check names.
- Produces: Twig `vite_entry_link_tags('app')` / `vite_entry_script_tags('app')`; build output in `public/build/` (`public/build/.vite/entrypoints.json`); `make asset-build` (`pnpm build`), `make asset-watch` (`pnpm exec vite build --watch`); module Stimulus controllers are discovered from `src/*/Presentation/assets/controllers/*_controller.js` and **must set `import.meta.stimulusIdentifier`** so their names don't change when they move; Vue components from `src/*/Presentation/assets/vue/**/*.vue` are mounted with `{{ vue_component('<Module>/<Path>', props) }}`; Tailwind utilities are used as `tw:<utility>` only.
- `createVueComponentResolver(modules: Record<string, {default: object}>): (name: string) => object`. A key ending `/src/<Module>/Presentation/assets/vue/<Path>.vue` resolves as `<Module>/<Path>`. An unknown name throws `Error('Vue component "<name>" not found. Known: <comma-separated names>')`.

- [ ] **Step 1: Write the Vitest test** `tests/Core/Javascript/createVueComponentResolver.test.js` (`// Initialize` / `// Execute` / `// Assert` sections). `resolvesByModuleAndPath`: keys `'../src/Attendance/Presentation/assets/vue/RollCallGrid.vue'` and `'../src/Grading/Presentation/assets/vue/sheet/GradeSheet.vue'` resolve as `'Attendance/RollCallGrid'` and `'Grading/sheet/GradeSheet'`. `throwsForUnknownNameListingKnownOnes`: the message is exactly `Vue component "Nope" not found. Known: Attendance/RollCallGrid, Grading/sheet/GradeSheet`. `mountsAnOptionsApiComponent`: resolve `Greeting.vue` (an Options API fixture with a `name` prop rendering `Hello {{ name }}`), mount it with `@vue/test-utils`, and assert the text `Hello Ana`.
- [ ] **Step 2: Swap AssetMapper for Vite** (one command each, full output read every time):
  - `make bash-exec CMD='composer config extra.symfony.allow-contrib true'` (the pentatrion recipe is a contrib recipe).
  - `make bash-exec CMD='composer remove symfony/asset-mapper'`. Then remove `importmap:install` from `composer.json` `auto-scripts` if the recipe left it, and delete `assets/vendor/` and `importmap.php` if they remain.
  - `make bash-exec CMD='composer require pentatrion/vite-bundle:^8.2 symfony/ux-vue:^3.7 -W'`. Review every recipe change with `git status`/`git diff`. Keep the recipe's `config/packages/pentatrion_vite.yaml`. Merge what it writes to `vite.config.js`/`assets/` into Steps 3 to 6 rather than keeping duplicates.
- [ ] **Step 3: Write `package.json`** (`private`, `type: module`, `packageManager: pnpm@<version from Task 1 Step 6>`). Scripts: `build: vite build`, `watch: vite build --watch`.
  - Dependencies: `@hotwired/stimulus 3.2.2` and `@hotwired/turbo 8.0.23` (exactly today's importmap versions), `@symfony/ux-turbo file:vendor/symfony/ux-turbo/assets`, `@symfony/ux-vue file:vendor/symfony/ux-vue/assets`, `vue ^3.5`.
  - devDependencies: `vite ^8.3`, `vite-plugin-symfony ^8.2` (same major.minor as the bundle), `@vitejs/plugin-vue ^6.0`, `tailwindcss ^4.3`, `@tailwindcss/vite ^4.3`, `vitest ^5.1`, `@vitest/coverage-v8 ^5.1`, `@vue/test-utils ^2.5`, `jsdom ^31`, `eslint ^10.14`, `@eslint/js ^10`, `eslint-plugin-vue ^10.12`, `vue-eslint-parser ^10.3`, `@stylistic/eslint-plugin ^5.10`, `globals ^17`, `stylelint ^18`, `stylelint-config-standard ^41`.

  Add `.npmrc` with `node-linker=hoisted` (Windows bind mounts, as in ams-core-02). Then run `make bash-exec CMD='pnpm install'` to create `pnpm-lock.yaml`. If Flex also wrote `@symfony/*` `file:` entries, keep one copy.
- [ ] **Step 4: Write `vite.config.js`**: plugins `vue()`, `tailwindcss()`, `symfonyPlugin({ stimulus: true })`; `build.rollupOptions.input.app = './assets/app.js'`; no dev server settings (builds only).
- [ ] **Step 5: Write `assets/bootstrap.js`** (the name HMR needs):
  - `import './controllers/csrf_protection_controller.js';` first, as a side-effect import.
  - `const app = startStimulusApp();` and `registerControllers(app, import.meta.glob(['./controllers/*_controller.js', '!./controllers/csrf_protection_controller.js', '../src/*/Presentation/assets/controllers/*_controller.js'], { query: '?stimulus', eager: true }))`, both from `vite-plugin-symfony/stimulus/helpers`.
  - `window.resolveVueComponent = createVueComponentResolver(import.meta.glob('../src/*/Presentation/assets/vue/**/*.vue', { eager: true }));`
  - `assets/app.js` imports `./bootstrap.js`, `./styles/app.css`, `./styles/admin.css`, `../src/Core/Presentation/assets/styles/tailwind.css` (in that order). `assets/controllers.json` keeps `turbo-core` as is and adds `@symfony/ux-vue` → `vue` with `enabled: true`, `fetch: eager`. Delete `assets/stimulus_bootstrap.js`. Controllers stay byte-for-byte unchanged.
- [ ] **Step 6: Write `createVueComponentResolver.js`** to the Interfaces signature, and **`tailwind.css`**. The CSS layers `theme, base, components, utilities`, imports only `tailwindcss/theme.css` and `tailwindcss/utilities.css` (no preflight) with prefix `tw`, and adds `@source` for `templates/` and `src/`. Confirm the exact prefix-with-split-imports syntax in the Tailwind 4.3 docs before writing it.
- [ ] **Step 7: Update `templates/base.html.twig`**: `{{ vite_entry_link_tags('app') }}` inside `stylesheets`, `{{ vite_entry_script_tags('app') }}` inside `javascripts` (replacing the `importmap` block). Keep the `turbo-prefetch` meta tag and its comment.
- [ ] **Step 8: Wire builds into Docker.** In `docker/entrypoint.sh`, after `composer install`, add `pnpm install --frozen-lockfile` and `pnpm build` with an echo line each, matching the file's style. In `make-config.yaml`, set `assets.build: pnpm build` and `assets.watch: pnpm exec vite build --watch`. Recreate `web` (`docker compose up --build -d`) and check the first run's log (`docker logs zimdave_schsys-web-1`) shows both pnpm steps finishing.
- [ ] **Step 9: Configure the JS checks** (modelled on ams-core-02's `config/` files):
  - `config/eslint.config.mjs`: `@eslint/js` recommended, `eslint-plugin-vue` `flat/recommended`, browser globals; `@stylistic` rules `indent 4`, `quotes single`, `semi always` (the existing controllers use semicolons), `comma-dangle always-multiline`; node globals for `tests/**/Javascript/**`; ignores `public/**`, `vendor/**`, `node_modules/**`, `var/**`.
  - `config/stylelint.config.cjs`: `stylelint-config-standard`, `at-rule-no-unknown` ignoring Tailwind's at-rules (`theme`, `source`, `utility`, `variant`, `custom-variant`, `plugin`, `reference`, `apply`), `import-notation: string`.
  - `config/vitest.config.js`: root `..`, `plugins: [vue()]`, `environment: jsdom`, `include: ['tests/**/Javascript/**/*.test.js']`, `pool: 'forks'`, `maxWorkers: 1` (shared machine; no parallel workers), coverage `v8` over `src/*/Presentation/assets/**/*.{js,vue}` with all four thresholds at `100`, reports in `reports/vitest/`.
  - In `docker/entrypoint_test.sh`, replace the placeholders:
    - eslint: `node ./node_modules/eslint/bin/eslint.js --config config/eslint.config.mjs --no-error-on-unmatched-pattern assets/app.js assets/bootstrap.js vite.config.js config 'src/*/Presentation/assets' 'tests/*/Javascript'`
    - stylelint: `node ./node_modules/stylelint/bin/stylelint.mjs --config config/stylelint.config.cjs --allow-empty-input 'src/*/Presentation/assets/**/*.css'`
    - vitest: `node ./node_modules/vitest/vitest.mjs run --config config/vitest.config.js --coverage`, or with `"$FILTER"` and no coverage when `FILTER` is set.
- [ ] **Step 10: Build once** (setup): `make asset-build`. Then check:
  - `public/build/.vite/entrypoints.json` lists `app`.
  - The built CSS has no Tailwind preflight rules: `grep -c 'box-sizing:border-box' public/build/assets/*.css` matches only what `app.css`/`admin.css` already had (compare with a grep of those sources).
  - The utilities layer is empty (`grep -o '@layer utilities{[^}]*}'` shows no selectors).

### Task 5: Demo seeder

**Files:**
- Modify: `composer.json` (via `composer require --dev`), `make-config.yaml` (`fixtures.seed`)
- Create: `src/DataFixtures/DemoFixtures.php`, `tests/DataFixtures/Integration/DemoFixturesTest.php`

**Interfaces:**
- Consumes: today's services, so plan limits and rules apply: `SchoolTeacherManager::create(School, SchoolYear, User): string`, `PrincipalAssigner::assign(School, SchoolYear, User): SchoolAssignment`, `SubjectManager::addDepEdSet(School, SchoolYear, GradeLevel): int`, `TimetableManager::setTeacher(Section, Subject, ?User): ?TeachingLoad`, `TimetableManager::plot(Section, Subject, Weekday, ClassPeriod): ScheduleEntry`, `LearnerEnrollment::enrollNew(Learner, Section, User): string`, `AttendanceRecorder::takeRollCall(Section, \DateTimeImmutable, array, User): RollCall`, `GradeRecorder::openPeriod(School, GradingPeriod, User): void`, `GradeRecorder::saveSheet(TeachingLoad, GradingPeriod, array, User): int`, `SchoolClock` for "today". Read each method before using it; the demo data must go through these, not around them.
- Produces: `DemoFixtures implements FixtureGroupInterface` with `getGroups(): array` returning `['demo']`; `make fixture-load` then `make fixture-seed`; constants `DemoFixtures::PASSWORD = 'demo1234'` and the usernames below (Task 7 documents them).

- [ ] **Step 1: Install**: `make bash-exec CMD='composer require --dev doctrine/doctrine-fixtures-bundle:^4.3 fakerphp/faker:^1.24'`. Check the recipe registered the bundle for dev/test and didn't create a sample `AppFixtures` (delete it if it did).
- [ ] **Step 2: Write `DemoFixturesTest extends KernelTestCase`** (uses `SchoolFixturesTrait`, `clearSchoolData()` in `setUp`, loads the fixture through `Doctrine\Common\DataFixtures\Executor\ORMExecutor` with `append: true`):
  - `testLoadsDemoData`: one division `Mandaue City`; two schools with ids `DEMO-001` (Basic) and `DEMO-002` (Individual); the current school year contains today and has 4 grading periods; users `demo.principal`, `demo.teacher1` … `demo.teacher6`, `demo.solo` exist with `mustChangePassword` false and password `demo1234` (check with `UserPasswordHasherInterface::isPasswordValid`); each `DEMO-001` section has 25 enrollments; at least one `RollCall`, one `Grade` and one `LessonPlan` exist.
  - `testStaysWithinPlanLimits`: `PlanLimits::blockedReason()` is null for both schools, and `canAddTeacher()` reports a full Individual school.
  - `testRefusesToLoadTwice` (`// Expect` first): loading again throws `\RuntimeException` with the message `Demo data is already loaded. Run make fixture-load first.`
- [ ] **Step 3: Write `DemoFixtures`** (Faker `en_PH`, `seed(2026)` so names repeat between runs):
  - Refuse when a school `DEMO-001` exists (message above).
  - Division `Mandaue City` (Region VII).
  - Current school year: June of this year to April of next (or the year before when today is before June), with 4 grading periods. Reuse the current one if it already exists.
  - `DEMO-001` "Mandaue City Demo National High School" (Junior High, Basic, 1 seat): principal `demo.principal`; teachers `demo.teacher1` to `demo.teacher6`; sections Grade 7, 8, 9 (one each) with the DepEd subject set; 8 class periods; a Monday-to-Friday timetable with every subject's teacher set; 25 learners per section; roll calls for the last 15 school days before today (a few absences and lates); first-quarter grades for every load; one lesson plan for `demo.teacher1`.
  - `DEMO-002` "Mandaue City Demo Learning Center" (Individual, 1 seat): teacher `demo.solo`, one section, 20 learners; a principal only if `PrincipalAssigner` allows it on that plan.
  - Every created staff user gets password `demo1234` (hashed) and `mustChangePassword` false. Learners keep their generated logins; the dev doesn't need them.
- [ ] **Step 4: Configure** `make-config.yaml`: `fixtures.seed.default: demo`, `fixtures.seed.scripts: [make bash-exec CMD='php bin/console doctrine:fixtures:load --group=$(FIXTURE_SEED) --append -n']`. Leave `fixtures.load` as is: it drops, creates and migrates, and the migrations seed the plans and `zim`.

### Task 6: Browser smoke tests (Panther)

**Files:**
- Modify: `composer.json` (via `composer require --dev`), `make-config.yaml` (`tests.regression`)
- Create: `tests/Browser/BrowserTestCase.php`, `tests/Browser/RoleSmokeTest.php`, `docker/test/run-regression-tests.sh`

**Interfaces:**
- Consumes: Task 2's `browser` suite and Panther env vars; Task 4's built assets.
- Produces: `make regression-test` (runs the `browser` suite inside `web`); `BrowserTestCase::logIn(Client $client, string $username, string $password = 'Secret123'): void` (fills `_username`/`_password` on `/login`, submits, waits for the next page's `h1`); later phases add their module's main flow.

- [ ] **Step 1: Install**: `make bash-exec CMD='composer require --dev symfony/panther:^2.4 dbrekelmans/bdi:^1.4'`. Read the recipe changes; keep `PANTHER_*` env lines only in `phpunit.dist.xml` (Task 2), not duplicated into `.env.test`.
- [ ] **Step 2: Write `BrowserTestCase extends PantherTestCase`** (uses `SchoolFixturesTrait`; `setUp` boots the kernel, gets the entity manager, runs `clearSchoolData()`). Users are created like `AdminTestCase::createUser()`: hashed `Secret123`, `mustChangePassword` false.
- [ ] **Step 3: Write `RoleSmokeTest`.** One test per role. Each test logs in through the real form, lands on the dashboard (its `h1`), clicks the first link of the side navigation (a real link, not a typed URL), then asserts the new page's `h1` is shown and the title isn't an error page:
  - `testAdminFlow` (`zim`)
  - `testPrincipalFlow` (principal assigned to a school in the current year)
  - `testTeacherFlow` (teacher assigned in the current year)
  - `testStudentFlow` (an enrolled learner whose user has a known password)

  `testPagesKeepTurboAndPrefetchSetting` (admin): `$client->executeScript('return typeof window.Turbo')` is `'object'`, and `meta[name="turbo-prefetch"]` has `content="false"`.
- [ ] **Step 4: Write `docker/test/run-regression-tests.sh`**: `set -e`; `pnpm install --frozen-lockfile && pnpm build`; `vendor/bin/bdi detect drivers` (installs a chromedriver matching the image's Chrome into `drivers/`); `php bin/phpunit --testsuite browser "$@"`. Add `/drivers/` to `.gitignore` (check with `git check-ignore -v`).
- [ ] **Step 5: Configure** `make-config.yaml` `tests.regression`: `test_suite: browser`, `test_options: '--testdox --colors=always'`, `test: [make bash-exec CMD='bash docker/test/run-regression-tests.sh $(TEST_OPTIONS) $(TEST_TARGET)']`.

### Task 7: Docs and skills

**Files:**
- Modify: `CLAUDE.md`, `AGENTS.md`, `.claude/skills/run-tests/SKILL.md`

- [ ] **Step 1: `CLAUDE.md`.**
  - "What this is": Vite (pentatrion) + Stimulus + Turbo, Vue 3 (Options API) and Tailwind 4 available (`tw:` prefix), pnpm/Node in Docker.
  - "Two ways to run it": the app still runs under XAMPP/Laragon, but tests, coverage, static checks and asset builds are Docker-only.
  - Gotchas: the CSRF side-effect import; module Stimulus controllers set `import.meta.stimulusIdentifier`; Tailwind classes only with `tw:`; `public/build` must exist for any Twig page (scripts build it).
  - New "Demo data" section: `make fixture-load` then `make fixture-seed`, the usernames and `demo1234`, and that it refuses a second load.
  - Tests: the suites, `make test <type>`, `make test-feature` (coverage gate), `make regression-test`, and where reports land (`reports/`).
- [ ] **Step 2: `AGENTS.md`.**
  - Docker: base image `1.1` with pcov/Node 24/pnpm/Chrome; the entrypoint also runs `pnpm install` and `pnpm build`; `make test`, `make test-feature`, `make regression-test`.
  - Testing: the suite layout `tests/<Module>/{Unit,Integration,Functional}`, `tests/Browser`, JS tests in `tests/<Module>/Javascript`.
  - Keep the phpMyAdmin line already added.
- [ ] **Step 3: `run-tests` skill**: Docker only (drop the local-PHP branch). Scoped runs are `make test-feature TEST_TARGET='--filter X'` or a file path, `make test phpunit FILTER=X` for unit tests, and `make test vitest FILTER=x`. The full run is `make test all`, then `make test-feature`, then `make regression-test`, one at a time, each as a blocking call. Clean means PHPUnit `OK (...)` plus the coverage-check pass line and a summary with no ❌, and the `make` exit code is not trusted. Measure the new full-run time in Task 9 and put it in the skill instead of "about 8 minutes".

### Task 8: Comment clean-up and deprecation check

- [ ] **Step 1: Comments.** Read every file this phase touched (`git diff develop --stat` plus new files). Make comments short, plain, only where needed, with no plan/task/tool references and no Claude/Anthropic mention. Strip ams-core-02-derived comments that explain ams-core-02's own history (emoji, Laravel/Dusk references).
- [ ] **Step 2: Deprecations.** Look for deprecated APIs in the touched code: Messenger (`getWrappedExceptions()`), Panther, Vite plugin options, Tailwind 4 directives, ESLint flat config (no `.eslintrc`), PHPUnit 13 attributes (no doc-comment annotations). Use `phpstan-deprecation-rules` findings from Task 9's first run, and fix whatever turns up. Run `composer outdated --direct` and `pnpm outdated` once (setup reads); list anything a major behind for the owner, without upgrading it here.

### Task 9: Verification

- [ ] **Step 1: Notes checkpoint (end).** Go through the global notes, `.claude/rules/team-notes.md`, `CLAUDE.md` and `AGENTS.md` category by category and confirm what was built follows them (no parallel runs, comments, no AI mentions, Docker rules, attribute/Flex rules, test section comments).
- [ ] **Step 2: Schema unchanged.** Run `make migration-clear` (`doctrine:schema:validate` in sync) and `make migration-diff`. Expected: "No changes detected"; remove any generated empty migration.
- [ ] **Step 3: One combined static + unit run.** Run `make test all` as one blocking call (timeout 600000 ms). Fix everything it flags at once. Re-run only the failing checks (`make test <type>`).
- [ ] **Step 4: Database suites with the coverage gate.** Run `make test-feature` as one blocking call (timeout 600000 ms; if it can exceed that, run it backgrounded and check `reports/phpunit/junit.xml` timestamps and the process directly). Expected: PHPUnit `OK (...)` with no deprecations, notices or warnings, test count = today's count plus the new tests, and coverage-check reporting 100% for `src/Core`. Fix failures and re-run only those tests (`TEST_TARGET='--filter ...'`).
- [ ] **Step 5: Browser suite.** `make regression-test`. Expected `OK (5 tests, ...)`.
- [ ] **Step 6: Final full run on the final code.** `make test all`, then `make test-feature`, then `make regression-test`, one at a time. Any code change after this restarts Step 6.
- [ ] **Step 7: Seeder for real.** `make fixture-load`, then `make fixture-seed`, then confirm the dev database through phpMyAdmin (`http://docker.vernie.com:8096`): schools `DEMO-001`/`DEMO-002` exist. Run `make fixture-seed` again and confirm it stops with the "already loaded" message.
- [ ] **Step 8: Ask the owner before any live-browser check** (team notes). Once confirmed: log in as `zim`, `demo.principal`, `demo.teacher1` at `https://docker.vernie.com:8095/`, click through the dashboards and one screen each, and compare with screenshots taken on `develop` before the switch (take those first, on the same pages). Confirm the timetable drag, attendance marking and image preview controllers still work.
- [ ] **Step 9: Clean up.** Stop Monitor tasks/background shells started for this work, remove scratch files, and make sure no stray `vite`/`php -S`/chromedriver process is left in `web` (`make bash-exec CMD='ps aux'`).
- [ ] **Step 10: Report.** Show the Timeline table, a list of changes, the mistakes made with proposed notes (global or project), and a proposed single commit message. Wait for approval before committing:

```
Lay the modular architecture foundation

Add the Core bus (use cases, queries, Result) on Symfony Messenger with
logging, error handling, transactions, validation and query caching.
Replace AssetMapper with Vite and add Vue 3 and Tailwind 4 (prefixed,
no preflight) without changing any page. Add PHPStan, deptrac, phpcs,
coverage and JS checks with baselines, a test image, Panther smoke
tests per role, a demo data seeder and phpMyAdmin. The Docker image
gains pcov, Node 24, pnpm and Chrome.
```
