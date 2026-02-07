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

cd "$PLUGIN_SUBDIR2

# 1) PHP lint
echo "== PHP lint =="
php -v > $REPORTS_DIR/php-version.txt || true
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n 1 -P 4 php -l \
  | tee $REPORTS_DIR/php-lint.txt

# 2) Composer dev tooling (optional)
if [ -f composer.json ]; then
  echo "== composer install (dev) =="
  composer install --no-interaction --no-progress --prefer-dist --no-scripts || true
fi

# 3) PHPCS/WPCS (if phpcs.xml.dist present)
if [ -f phpcs.xml.dist ] && [ -d vendor ]; then
  echo "== PHPCS =="
  ./vendor/bin/phpcs -q --report=full | tee $REPORTS_DIR/phpcs.txt || true
else
  echo "PHPCS skipped (missing phpcs.xml.dist or vendor)" | tee $REPORTS_DIR/phpcs.txt
fi

# 4) PHPStan (if phpstan.neon.dist present)
if [ -f phpstan.neon.dist ] && [ -d vendor ]; then
  echo "== PHPStan =="
  ./vendor/bin/phpstan analyse -c phpstan.neon.dist | tee $REPORTS_DIR/phpstan.txt || true
else
  echo "PHPStan skipped (missing phpstan.neon.dist or vendor)" | tee $REPORTS_DIR/phpstan.txt
fi

# 5) WP Integration tests (PHPUnit)
echo "== WP Tests install =="
chmod +x ../bin/install-wp-tests.sh
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

../bin/install-wp-tests.sh | tee $REPORTS_DIR/wp-tests-install.txt || true

echo "== PHPUnit =="
if [ -f phpunit.xml.dist ]; then
  ./vendor/bin/phpunit -c phpunit.xml.dist | tee $REPORTS_DIR/phpunit.txt || true
else
  echo "PHPUnit skipped (missing phpunit.xml.dist)" | tee $REPORTS_DIR/phpunit.txt
fi

# gate.json summary (simple)
python3 - <<'PY'
import json, os, pathlib
def read(p):
  try: return pathlib.Path(p).read_text(errors="ignore")
  except: return ""
gate = {"pass": True, "failures": []}
checks = [
  ("php_lint", "$REPORTS_DIR/php-lint.txt"),
  ("phpcs", "$REPORTS_DIR/phpcs.txt"),
  ("phpstan", "$REPORTS_DIR/phpstan.txt"),
  ("phpunit", "$REPORTS_DIR/phpunit.txt"),
]
for name, path in checks:
  txt = read(path)
  # Heuristic: treat "Parse error" or "ERROR" as failure. Tune as needed.
  failed = ("Parse error" in txt) or ("\nERROR" in txt) or ("Fatal error" in txt) or ("FAILURES!" in txt)
  if failed:
    gate["pass"] = False
    gate["failures"].append({"check": name, "evidence": path})
pathlib.Path("$REPORTS_DIR/gate.json").write_text(json.dumps(gate, indent=2))
print("Wrote $REPORTS_DIR/gate.json:", gate)
PY

echo "== Gate done =="
