#!/bin/bash
# Runs the database test suites inside the web container. With no arguments it
# runs them all with coverage and enforces the 100% gate; with arguments (for
# example --filter Name or a test file) it runs just those, without coverage.
set -e

# Twig pages need the built assets
if [ ! -f public/build/.vite/entrypoints.json ]; then
  pnpm install --frozen-lockfile
  pnpm build
fi

mkdir -p reports/phpunit

if [ $# -gt 0 ]; then
  php bin/phpunit "$@"
  exit
fi

php -d pcov.enabled=1 bin/phpunit --testsuite unit,integration,functional,legacy \
  --coverage-clover reports/phpunit/coverage.xml \
  --log-junit reports/phpunit/junit.xml
vendor/bin/coverage-check reports/phpunit/coverage.xml 100
