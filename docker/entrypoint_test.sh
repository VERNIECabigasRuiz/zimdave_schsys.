#!/bin/sh
# Runs the static checks and the unit tests, one after another, in a throwaway
# container. Usage: entrypoint_test.sh [type ...] (default: all). Set FILTER to
# scope phpunit/vitest to matching tests.

START_TIME=$(date +%s)

ALL_CHECKS="composer-audit phpstan deptrac phpcs eslint stylelint phpunit vitest"

usage() {
  echo "Usage: entrypoint_test.sh [composer-audit|phpstan|deptrac|phpcs|eslint|stylelint|phpunit|vitest|all] [...]"
}

# Pick the checks to run, in the fixed order above, each once
CHECKS=""
WANTED=""
for type in $(echo "${*:-all}" | tr ',' ' '); do
  case "$type" in
    all) WANTED="$ALL_CHECKS" ;;
    --help) usage; exit 0 ;;
    --no-phpunit | --no-parallel) ;;
    composer-audit | phpstan | deptrac | phpcs | eslint | stylelint | phpunit | vitest) WANTED="$WANTED $type" ;;
    *)
      echo "Unknown test type: $type"
      usage
      exit 1
      ;;
  esac
done
[ -z "$WANTED" ] && WANTED="$ALL_CHECKS"
for check in $ALL_CHECKS; do
  case " $WANTED " in
    *" $check "*) CHECKS="$CHECKS $check" ;;
  esac
done

echo "Test started ($CHECKS${FILTER:+, FILTER=$FILTER})..."

git config --global --add safe.directory '*'

# No database runs here; the URL only fixes the platform for Doctrine metadata
export DATABASE_URL='mysql://root:password@127.0.0.1:3306/zimdave_schsys?serverVersion=8.4.0&charset=utf8mb4'

echo "Installing dependencies..."
composer install -n || exit 1
pnpm install --frozen-lockfile || exit 1
php bin/console cache:warmup --env=dev || exit 1

for check in $ALL_CHECKS; do
  mkdir -p "reports/$check"
done

run_composer_audit() {
  composer audit --abandoned=ignore -n
}

run_phpstan() {
  vendor/bin/phpstan analyse -c config/phpstan.neon --memory-limit=1G --no-progress
}

run_deptrac() {
  vendor/bin/deptrac analyse --config-file=config/deptrac.yaml --cache-file=reports/deptrac/.cache --report-uncovered --no-progress
}

run_phpcs() {
  vendor/bin/phpcs --standard=config/phpcs.xml
}

run_eslint() {
  node ./node_modules/eslint/bin/eslint.js --config config/eslint.config.mjs --no-error-on-unmatched-pattern \
    assets/app.js assets/bootstrap.js vite.config.js config 'src/*/Presentation/assets' 'tests/*/Javascript'
}

run_stylelint() {
  node ./node_modules/stylelint/bin/stylelint.mjs --config config/stylelint.config.cjs --allow-empty-input \
    'src/*/Presentation/assets/**/*.css'
}

# Coverage needs the database suites, so it's checked by make test-feature
run_phpunit() {
  if [ -n "$FILTER" ]; then
    php bin/phpunit --testsuite unit --filter "$FILTER"
  else
    php bin/phpunit --testsuite unit --log-junit reports/phpunit/junit.xml
  fi
}

run_vitest() {
  if [ -n "$FILTER" ]; then
    node ./node_modules/vitest/vitest.mjs run --config config/vitest.config.js "$FILTER"
  else
    node ./node_modules/vitest/vitest.mjs run --config config/vitest.config.js --coverage
  fi
}

SUMMARY=""
FAILED=0
for check in $CHECKS; do
  echo ""
  echo "Running $check..."
  started=$(date +%s)
  if "run_$(echo "$check" | tr '-' '_')"; then
    mark="✅"
  else
    mark="❌"
    FAILED=1
  fi
  SUMMARY="$SUMMARY
  $mark $check ($(($(date +%s) - started))s)"
done

ELAPSED=$(($(date +%s) - START_TIME))
echo ""
echo "Summary:$SUMMARY"
echo ""
echo "Done in $((ELAPSED / 60))m $((ELAPSED % 60))s"

exit $FAILED
