---
name: new-migration
description: Create and apply a Doctrine migration after changing entities in src/Entity. Use whenever the database schema needs to change.
---

Never use `doctrine:schema:update` or hand-written SQL for schema changes.

1. Detect the setup like `/run-tests` does (Docker if `zimdave_schsys-web-1` is running, else local PHP 8.4). In Docker, prefix each command with `make bash-exec CMD='...'`.
2. Check the mapping first: `php bin/console doctrine:schema:validate --skip-sync`.
3. Generate: `php bin/console doctrine:migrations:diff -n`. Open the new file in `migrations/`:
   - It must contain only the intended change; remove unrelated drift and report it instead.
   - SQL must be MySQL syntax (backtick-quoted `user`, `DEFAULT CHARACTER SET utf8mb4`), matching the existing migrations.
   - Give it a short `getDescription()` and a working `down()`.
   - Seed/default rows (like the `Basic`/`Individual` plans or the `zim` admin) go in a migration, guarded so they don't duplicate.
4. Apply to the dev DB: `php bin/console doctrine:migrations:migrate -n`.
5. Apply to the test DB: `php bin/console doctrine:migrations:migrate -n --env=test`.
6. Confirm `php bin/console doctrine:schema:validate` reports mapping and database in sync, then run `/run-tests` for the affected entities.
