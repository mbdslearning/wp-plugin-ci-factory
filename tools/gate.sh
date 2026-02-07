#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SUBDIR="${PLUGIN_SUBDIR:-.}"
WP_VERSION="${WP_VERSION:-latest}"
WP_DEBUG="${WP_DEBUG:-0}"
SCRIPT_DEBUG="${SCRIPT_DEBUG:-0}"

REPO_ROOT="$(pwd)"
REPORTS_DIR="$REPO_ROOT/reports"
mkdir -p "$REPORTS_DIR"

echo "== Gate: plugin_subdir=$PLUGIN_SUBDIR wp=$WP_VERSION =="
echo "WP_DEBUG=$WP_DEBUG SCRIPT_DEBUG=$SCRIPT_DEBUG"

cd "$PLUGIN_SUBDIR"

FAIL=0
STATUS_PHP_LINT="skipped"
STATUS_COMPOSER="skipped"
STATUS_PHPCS="skipped"
STATUS_PHPSTAN="skipped"
STATUS_PHPUNIT="skipped"

# Helper: run a command, capture exit code without aborting the script
run_step() {
  local name="$1"
  local outfile="$2"
  shift 2

  echo "== ${name} =="

  set +e
  "$@" | tee "$outfile"
  local rc=${PIPESTATUS[0]}
  set -e

  if [ "$rc" -eq 0 ]; then
    echo "${name} OK"
    return 0
  else
    echo "${name} FAILED (exit ${rc})"
    return "$rc"
  fi
}

# 1) PHP lint
php -v > "$REPORTS_DIR/php-version.txt" || true
echo "== PHP lint =="
set +e
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n 1 -P 4 php -l | tee "$REPORTS_DIR/php-lint.txt"
rc_lint=${PIPESTATUS[1]}
set -e
STATUS_PHP_LINT=$([ "$rc_lint" -eq 0 ] && echo "ok" || echo "fail")
if [ "$rc_lint" -ne 0 ]; then FAIL=1; fi

# 2) Composer dev tooling (optional)
if [ -f composer.json ]; then
  echo "== composer install (dev) =="
  set +e
  composer install --no-interaction --no-progress --prefer-dist --no-scripts | tee "$REPORTS_DIR/composer-install.txt"
  rc_comp=${PIPESTATUS[0]}
  set -e
  STATUS_COMPOSER=$([ "$rc_comp" -eq 0 ] && echo "ok" || echo "fail")
  if [ "$rc_comp" -ne 0 ]; then FAIL=1; fi
else
  echo "composer skipped (missing composer.json)" | tee "$REPORTS_DIR/composer-install.txt"
fi

# 3) PHPCS/WPCS
if [ -f phpcs.xml.dist ] && [ -d vendor ]; then
  set +e
  ./vendor/bin/phpcs -q --report=full | tee "$REPORTS_DIR/phpcs.txt"
  rc_phpcs=${PIPESTATUS[0]}
  set -e
  STATUS_PHPCS=$([ "$rc_phpcs" -eq 0 ] && echo "ok" || echo "fail")
  if [ "$rc_phpcs" -ne 0 ]; then FAIL=1; fi
else
  echo "PHPCS skipped (missing phpcs.xml.dist or vendor)" | tee "$REPORTS_DIR/phpcs.txt"
fi

# 4) PHPStan
if [ -f phpstan.neon.dist ] && [ -d vendor ]; then
  set +e
  ./vendor/bin/phpstan analyse -c phpstan.neon.dist | tee "$REPORTS_DIR/phpstan.txt"
  rc_phpstan=${PIPESTATUS[0]}
  set -e
  STATUS_PHPSTAN=$([ "$rc_phpstan" -eq 0 ] && echo "ok" || echo "fail")
  if [ "$rc_phpstan" -ne 0 ]; then FAIL=1; fi
else
  echo "PHPStan skipped (missing phpstan.neon.dist or vendor)" | tee "$REPORTS_DIR/phpstan.txt"
fi

# 5) WP integration tests (PHPUnit)
echo "== WP tests install =="
chmod +x "$REPO_ROOT/bin/install-wp-tests.sh"
WP_TESTS_DIR="/tmp/wordpress-tests-lib"
WP_CORE_DIR="/tmp/wordpress"
export WP_TESTS_DIR WP_CORE_DIR
export DB_NAME="${DB_NAME:-wordpress_test}"
export DB_USER="${DB_USER:-root}"
export DB_PASS="${DB_PASS:-root}"
export DB_HOST="${DB_HOST:-127.0.0.1}"
export DB_PORT="${DB_PORT:-3306}"
export WP_VERSION
export WP_DEBUG SCRIPT_DEBUG
export PLUGIN_DIR="$(pwd)"

set +e
"$REPO_ROOT/bin/install-wp-tests.sh" | tee "$REPORTS_DIR/wp-tests-install.txt"
rc_wpt=${PIPESTATUS[0]}
set -e
# wp-tests install failures should fail the gate because PHPUnit won't run meaningfully
if [ "$rc_wpt" -ne 0 ]; then FAIL=1; fi

echo "== PHPUnit =="
if [ -f phpunit.xml.dist ] && [ -d vendor ]; then
  set +e
  ./vendor/bin/phpunit -c phpunit.xml.dist | tee "$REPORTS_DIR/phpunit.txt"
  rc_phpunit=${PIPESTATUS[0]}
  set -e
  STATUS_PHPUNIT=$([ "$rc_phpunit" -eq 0 ] && echo "ok" || echo "fail")
  if [ "$rc_phpunit" -ne 0 ]; then FAIL=1; fi
else
  echo "PHPUnit skipped (missing phpunit.xml.dist or vendor)" | tee "$REPORTS_DIR/phpunit.txt"
fi

export FAIL STATUS_PHP_LINT STATUS_COMPOSER STATUS_PHPCS STATUS_PHPSTAN STATUS_PHPUNIT

# gate.json summary (authoritative: based on exit codes above, not log heuristics)
python3 - <<'PY'
import json, os, pathlib
reports = pathlib.Path("reports")
data = {
  "pass": os.environ.get("FAIL", "0") == "0",
  "checks": {
    "php_lint": os.environ.get("STATUS_PHP_LINT", "unknown"),
    "composer": os.environ.get("STATUS_COMPOSER", "unknown"),
    "phpcs": os.environ.get("STATUS_PHPCS", "unknown"),
    "phpstan": os.environ.get("STATUS_PHPSTAN", "unknown"),
    "phpunit": os.environ.get("STATUS_PHPUNIT", "unknown"),
  }
}
reports.mkdir(exist_ok=True)
(reports / "gate.json").write_text(json.dumps(data, indent=2))
print("Wrote reports/gate.json:", data)
PY

echo "== Gate done =="

if [ "$FAIL" -ne 0 ]; then
  echo "Gate failed according to exit codes."
  exit 1
fi
