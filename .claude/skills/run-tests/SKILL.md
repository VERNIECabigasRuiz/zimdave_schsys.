---
name: run-tests
description: Run this project's PHPUnit tests in the active setup (Docker container or local PHP), scoped to the affected tests first, then one full run. Use after any PHP/Twig change or when asked to run tests.
---

1. Detect the setup: if `docker ps --format '{{.Names}}'` lists `zimdave_schsys-web-1`, use Docker; otherwise use local PHP 8.4 (`php -v` must say 8.4).
   - Docker: `make bash-exec CMD='php bin/phpunit <args>'` (or `MSYS_NO_PATHCONV=1 docker exec zimdave_schsys-web-1 php bin/phpunit <args>` if `make` is unavailable).
   - Local: `php bin/phpunit <args>`. The `_test` database must exist and be migrated (`--env=test`).
2. Find the affected tests: grep `tests/` for the changed classes, routes and templates. Run only those first with `--filter '<Class or method>'` or a test file path. `$ARGUMENTS`, if given, is the filter/path to use.
3. Fix failures from evidence (test output, `var/log/test.log`), then re-run only the failing tests.
4. Finish with one full run (`php bin/phpunit`, no filter). It takes about 8 minutes in Docker on Windows; run it as a single blocking command with a long timeout, never in parallel with other test or analysis commands.
5. A run is only clean with `OK (...)` and no deprecations, notices or warnings (`phpunit.dist.xml` fails on all three). Don't trust `make`'s exit code: the Base Makefile can return 0 even when tests fail, so read PHPUnit's summary line.
