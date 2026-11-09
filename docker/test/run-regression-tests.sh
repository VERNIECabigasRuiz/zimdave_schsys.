#!/bin/bash
# Runs the browser tests inside the web container, in headless Chrome.
set -e

pnpm install --frozen-lockfile
pnpm build

# A chromedriver matching the installed Chrome, in drivers/
vendor/bin/bdi detect drivers

php bin/phpunit --testsuite browser "$@"
