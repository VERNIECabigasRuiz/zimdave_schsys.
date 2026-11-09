---
name: run-tests
description: Run this project's tests and static checks in Docker, scoped to the affected tests first, then one full run. Use after any PHP/Twig/JS change or when asked to run tests.
---

Tests and checks run in Docker only; `zimdave_schsys-web-1` must be running (`docker ps`). Run every command below on its own, as a blocking call, never in parallel with another test or check.

1. Find the affected tests: grep `tests/` for the changed classes, routes, templates and JS files. Run only those first. `$ARGUMENTS`, if given, is the filter or path to use.
   - Database tests (integration, functional): `make test-feature TEST_TARGET='--filter <Class or method>'`, or a test file path as `TEST_TARGET`.
   - Unit tests: `make test phpunit FILTER=<Name>`.
   - JS tests: `make test vitest FILTER=<file name part>`.
   - One static check: `make test phpstan` (or `deptrac`, `phpcs`, `eslint`, `stylelint`, `composer-audit`).
2. Fix failures from evidence (test output, `var/log/test.log`, `reports/`), then re-run only the failing tests.
3. Finish with the full run, one command at a time: `make test all`, then `make test-feature` (all database suites with the 100% coverage gate on the moved modules), then `make regression-test` (browser tests in headless Chrome). The full run takes about 10 minutes in Docker on Windows (about 2, 7 and 1 minutes); give each a long timeout.
4. A run is clean only when:
   - `make test all` ends with a summary that has no ❌ line;
   - `make test-feature` shows PHPUnit's `OK (...)` with no deprecations, notices or warnings (`phpunit.dist.xml` fails on all three), followed by the coverage check's pass line;
   - `make regression-test` shows `OK (...)`.

   Don't trust `make`'s exit code: the Base Makefile can return 0 even when tests fail, so read the summary lines.
