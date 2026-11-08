# Modular architecture, Phase 2c (Billing) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move plans (the `Plan` entity, its numbers and the admin plan screens) into a `Billing` module with DDD layers, every action through the Core bus, without anything a user would notice changing.

**Architecture:** Same layout and patterns as `Access` (Phase 2b): `Domain/Model`, `Domain/Model/Repository`, `Domain/Service/<Area>`, `Domain/Exception`, `Application/UseCase|Query/{Command,Handler}`, `Application/Transformer`, `Infrastructure/<Area>`, `Presentation/{Controller,Form,Validation,templates}`. The plan admin needs each plan's school count, which is School data; it reaches Billing through a hook (`PlanUsageInterface`) implemented for now by a legacy class.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, Messenger (Core bus), Twig, PHPUnit 13, PHPStan 2.3, deptrac 4.7, phpcs 4.0.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 4 row `Billing`, sections 5, 6, 8, 10). Owner decision (2026-11-07): `PlanLimits` and `PlanLimitException` are not part of Billing; they read school, section and enrollment data and move with Schools (Phase 2d). They stay legacy here.

**Branch:** `feature/modular-architecture` (Phase 2b committed as `a7486a4`, notes as `3bfa647`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 1. Billing domain, persistence and wiring | 02:13:30 | 02:16:25 | 3m |
| 2. Plan administration | 02:16:25 | 02:19:50 | 3m |
| 3. Baselines, docs, comments, deprecations | 02:19:50 | 02:33:58 | 14m |
| 4. Verification | 02:33:58 | 03:19:06 | 45m |

## Global Constraints

- Structure only: URLs, route names, form field names (`plan_form[...]`), flash messages, error texts, activity-log lines and access rules stay as today. Table and column names stay; `doctrine:migrations:diff` stays empty; the route table matches HEAD.
- Billing may use Core only. Legacy may use Billing. The school count per plan reaches Billing only through `PlanUsageInterface`.
- Billing Domain may use `Doctrine\ORM\Mapping`, `Doctrine\Common\Collections` and Core Domain; nothing from Symfony. Mapping types as strings (no `Doctrine\DBAL\Types\Types`).
- Controllers extend `Core\Presentation\Controller\BusController`, make one bus call per action and show a `DomainException` as today's flash or form error. Handlers refuse by throwing a Billing/Core `DomainException`. Forms bind to the bus Command objects. Hook wiring is registered in code (as `AccessHookAutoconfiguration`), not in `services.yaml`.
- New code: `declare(strict_types=1)`, PHPStan level 9 with no baseline entries, phpcs PSR-12 (lines ≤ 120), 100% coverage of `src/Core`, `src/Access`, `src/Billing`.
- Moved tests keep their assertions. New tests use `// Initialize`, `// Execute`, `// Assert` (`// Expect` first) and the existing helpers (`AdminTestCase`, `SchoolFixturesTrait`). Test helpers never reuse PHPUnit `TestCase` method names (`output`, `name`, …).
- Edits with backslashes go through the file tools or scratchpad scripts, never inline `sed`. When wrapping a signature as `): Type {`, replace the old `{` line too.
- Tests are written per task and run once in Task 4, one command at a time, in Docker. `MSYS_NO_PATHCONV=1` for `docker exec` with container paths; deptrac with `--cache-file=reports/deptrac/.cache`.
- PHPStan baseline: delete `reports/phpstan/.cache`, prune entries for deleted files, regenerate; the total must not grow and no entry may name `src/Billing`, `tests/Billing`, `src/Access`, `src/Core`, their tests, or a file not on `develop`.
- Live-browser check: ask first and confirm headless or visible (default visible). Comments short and plain; no Claude/Anthropic mention. One commit at the end after approval, through the Base hook.

## Review Focus

1. **Deleting a plan that schools use.** Refused with "<name> is used by <n> school(s) and cannot be deleted.", and the plan stays. Pinned by the moved `PlanControllerTest` and `PlanHandlersTest::testDeleteRefusesAPlanInUse` (Task 2).
2. **Duplicate plan names.** "This plan already exists." under the name field, shown together with any other field errors, on create and on edit. Pinned by `PlanControllerTest::testDuplicateNameIsRefusedWithTheOtherErrors` (Task 2).
3. **The school form's plan dropdown.** It lists plans by name; its query builder must accept the plain Doctrine repository Billing's entity now gets. Pinned by the existing `SchoolControllerTest` create/edit tests (Task 1 changes the type hint).
4. **A new school starts on the Basic plan.** `SchoolController::new()` reads it through `PlanRepositoryInterface::findByName('Basic')`. Pinned by `PlanRepositoryTest::testFindsByName` and the existing school tests.
5. **Unknown plan ids** on edit and delete answer 404, as the entity argument did. Pinned by `PlanControllerTest::testUnknownPlanIs404` (Task 2).

## Rulings in this plan

- `PlanRepository::countSchoolsByPlan()` names `School`, so it moves to the legacy `SchoolRepository` as `countByPlan(): array<int, int>`; `CurrentPlanUsage` (legacy hook) calls it.
- `SchoolFormType`'s plan `query_builder` closure takes `EntityRepository` instead of `PlanRepository` (same query).
- The entity's `UniqueEntity` becomes a form-level `UniquePlanName` constraint (so the error shows with the others, as before) plus a handler check for races; its `NotBlank`/`Length`/`NotNull`/`Positive` rules move onto the commands with the same defaults. The form's text fields trim input, as the entity setter did.
- A forged CSRF token on an unknown plan id gets the token refusal instead of a 404 (the id is no longer resolved before the check); a valid token on a deleted plan still gets 404. Same as Access.

---

### Task 1: Billing domain, persistence and wiring

**Files:**
- Move: `src/Entity/Plan.php` → `src/Billing/Domain/Model/Plan.php` (`App\Billing\Domain\Model\Plan`; no `repositoryClass`, no `Assert`/`UniqueEntity`; same table, columns, getters/setters; `setName()` still trims)
- Create: `src/Billing/Domain/Model/Repository/PlanRepositoryInterface.php`, `src/Billing/Infrastructure/Repository/DoctrinePlanRepository.php`, `src/Billing/Domain/Service/PlanUsageInterface.php` (one implementation, autowired; no tag needed), `src/Service/BillingHooks/CurrentPlanUsage.php` (legacy)
- Delete: `src/Repository/PlanRepository.php`
- Modify: `config/packages/doctrine.yaml` (mapping `Billing`), `config/packages/twig.yaml` (`src/Billing/Presentation/templates` as `Billing`; create the folder), `config/deptrac.yaml` (layers `Billing_*` shaped like `Access_*` without `SecurityUserContract`; `Legacy` excludes `Billing\\`; `Legacy` may use the Billing layers), `phpunit.dist.xml` (`<source>` adds `src/Billing`), `config/phpcs.xml` (adds `src/Billing`, `tests/Billing`), `src/Repository/SchoolRepository.php` (gains `countByPlan()`), `src/Form/SchoolFormType.php`, `src/Controller/Admin/SchoolController.php`, every file importing or naming `App\Entity\Plan` (`School` uses it unimported in `App\Entity`)
- Test: `tests/Billing/Integration/Infrastructure/PlanRepositoryTest.php`, `tests/Billing/Unit/Domain/PlanTest.php`

**Interfaces:**
- Produces:
  - `PlanRepositoryInterface`: `findById(int $id): ?Plan`, `findByName(string $name): ?Plan`, `findAllOrdered(): list<Plan>` (by name), `add(Plan $plan): void`, `remove(Plan $plan): void` (no flush in either).
  - `PlanUsageInterface::schoolCountsByPlan(): array<int, int>` (school count keyed by plan id; plans without schools are absent).
  - `CurrentPlanUsage` implements it with `SchoolRepository::countByPlan()` (body moved from `PlanRepository::countSchoolsByPlan()`).

- [ ] **Step 1: Write the tests.** `PlanRepositoryTest` (KernelTestCase; plans named `rt-…`, deleted in `setUp`; the seeded `Basic` and `Individual` stay): `testFindsByName` (`findByName('Basic')` is the seeded plan; unknown name → null), `testFindAllOrderedIsByName`, `testAddAndRemoveWaitForFlush`, `testFindsById`. `PlanTest`: name trimmed, numbers, principal flag.
- [ ] **Step 2: Move the entity, add the repository, hook and legacy hook, config, deptrac.** Rewrite imports with `rewrite.py`/`adduse.py` (add a `plan` rule set). `SchoolController::new()` and `SchoolFormType` use the interface / `EntityRepository`.
- [ ] **Step 3: Setup checks:** `cache:clear`, `lint:container`, `doctrine:schema:validate`, `doctrine:migrations:diff --allow-empty-diff -n` → no changes.

### Task 2: Plan administration

**Files:**
- Create: `src/Billing/Application/UseCase/Command/{CreatePlanCommand,UpdatePlanCommand,DeletePlanCommand}.php`, `src/Billing/Application/Query/Command/{ListPlansCommand,GetPlanCommand}.php`, their handlers, `src/Billing/Application/Transformer/PlanTransformer.php`, `src/Billing/Domain/Exception/{PlanNameTakenException,PlanInUseException,PlanNotFoundException}.php`, `src/Billing/Presentation/Controller/PlanController.php` (extends `BusController`; keeps the old class-level `#[IsGranted(User::ROLE_ADMIN)]` and `#[Route('/admin/plans')]`), `src/Billing/Presentation/Form/PlanFormType.php` (moved; class name kept so fields stay `plan_form[...]`), `src/Billing/Presentation/Validation/{UniquePlanName,UniquePlanNameValidator}.php`
- Move templates: `templates/admin/plans/{index,form}.html.twig` → `src/Billing/Presentation/templates/admin/plans/`
- Delete: `src/Controller/Admin/PlanController.php`, `src/Form/PlanFormType.php`
- Move tests: `tests/Controller/Admin/PlanControllerTest.php` → `tests/Billing/Functional/PlanControllerTest.php`
- Test (new): `tests/Billing/Unit/Application/PlanHandlersTest.php`, `tests/Billing/Unit/Presentation/UniquePlanNameValidatorTest.php`, new cases in `PlanControllerTest`

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `CreatePlanCommand` (public `string $name`, `?int $teachersPerSeat`, `?int $studentsPerSeat`, `bool $includesPrincipal = true`; name `NotBlank`, `Length(max: 50)`; numbers `NotNull`, `Positive`) → refuses a taken name (`PlanNameTakenException`, `PLAN_NAME_TAKEN`, "This plan already exists."), logs "Created plan <name>", returns `['name' => string]`.
  - `UpdatePlanCommand` (same fields plus `public readonly int $id`; `fromPlan(array $plan): self`) → `PlanNotFoundException` (`PLAN_NOT_FOUND`), name check excluding itself, logs "Updated plan <name>", returns the plan array.
  - `DeletePlanCommand(int $id)` → `PlanNotFoundException`; `PlanInUseException` (`PLAN_IN_USE`, "%s is used by %d school(s) and cannot be deleted.") when `PlanUsageInterface` counts schools; else removes, logs "Deleted plan <name>", returns `['name' => string]`.
  - `ListPlansCommand()` → `['plans' => list<plan array>, 'schoolCounts' => array<int, int>]`; `GetPlanCommand(int $id)` → plan array or `PlanNotFoundException` (controller 404).
  - `PlanTransformer::toArray(Plan $plan): array{id: int, name: string, teachersPerSeat: ?int, studentsPerSeat: ?int, includesPrincipal: bool}`.
  - Controller: today's routes, paths, methods, flashes ("Plan %s created.", "Plan %s saved.", "Plan %s deleted.", the in-use error) and CSRF id `'"plan-" ~ args["id"]'`; templates receive `plans`, `school_counts`, `plan` (array or null) as today.

- [ ] **Step 1: Move the test** (assertions unchanged; namespace `App\Tests\Billing\Functional`) and add: `testDuplicateNameIsRefusedWithTheOtherErrors` (create with a taken name and blank numbers → 422, "This plan already exists." and the number errors in `.form-card`; edit another plan to a taken name → 422 with the message), `testUnknownPlanIs404` (edit GET and delete with a valid token taken from the list page after removing the plan → 404). `PlanHandlersTest`: every handler's success and refusal paths (`testDeleteRefusesAPlanInUse`, not-found cases, name taken on create and edit, edit keeping its own name, log arrays). `UniquePlanNameValidatorTest`: taken/free on create, own name on edit, another's name on edit, blank name, other values, wrong constraint, class target.
- [ ] **Step 2: Implement** commands, handlers, transformer, exceptions, controller, form, constraint, templates (`@Billing/admin/plans/...`).
- [ ] **Step 3: Setup checks:** `lint:twig` on all template folders, route table identical to HEAD (`scratchpad/routes.php`).

### Task 3: Baselines, docs, comments, deprecations

- [ ] **Step 1:** phpcbf, then phpcs clean (wrap lines > 120 by hand, through scratchpad scripts). PHPStan on `src/Billing src/Access src/Core tests/Billing tests/Access tests/Core` with no errors; then baseline regeneration under the Global Constraints rules; deptrac 0 violations.
- [ ] **Step 2: Docs.** `CLAUDE.md`: Billing module (plans and plan admin; `Plan` is `App\Billing\Domain\Model\Plan`; `PlanLimits`/`PlanLimitException` stay legacy until Schools moves; the `PlanUsageInterface` hook and its legacy implementation). Domain-terms line about plans/seats updated.
- [ ] **Step 3: Comment sweep and deprecation check** (`debug:container --deprecations`).

### Task 4: Verification

- [ ] **Step 1: Scoped run:** `make bash-exec CMD='bash docker/test/run-feature-tests.sh tests/Billing tests/Access tests/Core tests/Controller tests/Service'`; fix, re-run only failures.
- [ ] **Step 2: Coverage check on module tests alone:** `php -d pcov.enabled=1 bin/phpunit tests/Billing tests/Access tests/Core --coverage-clover ...` and `scratchpad/uncovered.py` → nothing uncovered.
- [ ] **Step 3: Full runs on the final code, one at a time:** `make test all` (8/8), `make test-feature` (100.00%), `make regression-test` (5); schema valid, diff empty; route table identical to HEAD.
- [ ] **Step 4: Fresh whole-change review** (most capable model, read-only); fix Critical/Important with a failing test first; full runs again.
- [ ] **Step 5: Ask the owner before the live-browser check, and whether headless or visible** (default visible). If headless: the develop-vs-branch screenshot and PDF comparison, the controller and photo flows, and a plan flow (create, duplicate name, edit, delete an unused plan, refuse deleting a used one) on both.
- [ ] **Step 6: Report** and propose the commit; commit only after approval:

```
Move plans into the Billing module

Move the plan entity and the admin plan screens into a Billing module whose
actions all go through the bus. The school count per plan reaches it through
a hook the schools part implements. Plan seat checks stay with schools.
Pages, messages and URLs are unchanged.
```
