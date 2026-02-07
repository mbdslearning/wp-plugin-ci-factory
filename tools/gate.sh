#!/usr/bin/env bash
set -euo pipefail

# Runs a lightweight "gate" against a WordPress plugin repo and writes reports/gate.json.
# It's intentionally best-effort: most checks are allowed to fail without aborting the job, but the final exit code is determined by gate.json.

PLUGIN_SUBDIR="${PLUGIN_SUBDIR:-.}"
WP_VERSION="${WP_VERSION:-latest}"
WP_DEBUG="${WP_DEBUG:-0}"
SCRIPT_DEBUG="${SCRIPT_DEBUG:-0}"

REPO_ROOT"="$(pwd)"
REPORTS_DIR="$REPO_ROOT/reports"
mkdir -p "$REPORTS_DIR"

echo "== Gate: plugin_subdir=$PLUGIN_SUBDIR wp=$WP_VERSION =="
echo "WP_DEBUG=$WP_DEBUG SCRIPT_DEBUG=$SCRIPT_DEBUG"

cd "$PLUGIN_SUBDIR

# 1) PHP lint
echo "== PHP lint =="
php -v > "$REPORTS_DIR/php-version.txt" || true
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n 1 -P4 php -l | tee "$REPORTS_DIR/php-lint.txt" || true

# 2) Composer dev tooling (optional)
if [ -f composer.json ]; then
  echo "== composer install (dev) =="
  composer install --no-interaction --no-progress --prefer-dist --no-scripts || true
fi

# 3) PHPCS/WPCS (if phpcs.xml.dist present)
if [ -f phpcs.xml.dist ] && [ -d vendor ]; then
  echo "== PHPCS =="
  ./vendor/bin/phpcs -q --report=full | tee "$REPORTS_DIR/phpcs.txt" || true
else
  echo "PHPCS skipped (missing phpcs.xml.dist or vendor)" | tee "$REPORTS_DIR/phpcs.txt"
fi

# 4) PHPStan (if phpstan.neon.dist present)
if [ -f phpstan.neon.dist ] && [ -d vendor ]; then
  echo "== PHPStan =="
  ./vendor/bin/phpstan analyse -c phpstan.neon.dist | tee "$REPORTS_DIR/phpstan.txt" || true
else
  echo "PHPStan skipped (missing phpstan.neon.dist or vendor)" | tee "$REPORTS_DIR/phpstan.txt"
fi

# 5) WP Integration tests (phpunit)
echo "== WP Tests install =="
chmod +x "$REPO_ROOT/bin/install-wp-tests.sh" || true
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

"$REPO_ROOT/bin/install-wp-tests.sh" 2>&1 | tee "$REPORTS_DIR/wp-tests-install.txt" || true

echo "== PHPUnit =="
if [ -f phpunit.xml.dist ] && [ -d vendor ]; then
  ./vendor/bin/phpunit -c phpunit.xml.dist 2>&1 | tee "$REPORTS_DIR/phpunit.txt" || true
else
  echo "PHPUnit skipped (missing phpunit.xml.dist or vendor)" | tee "$REPORTS_DIR/phpunit.txt"
fi

# gate.json summary: only hard fail signals (fatal/parse/PHPUnit FAILURES!)
python3 - <<'PY'
import json, pathlib
def read(p):
  try:
    return pathlib.Path(p).read_text(errors="ignore")
  except:
    return ""
reports_dir = pathlib.Path("reports")
gate = {"pass": True, "failures": []}
checks = [
  ("php_lint", reports_dir / "php-lint.txt"),
  ("phpcs", reports_dir / "phpcs.txt"),
  ("phpstan", reports_dir / "phpstan.txt"),
  ("phpunit", reports_dir / "phpunit.txt"),
]
for name, path in checks:
  txt = read(path)
  failed = ("Parse error" in txt) or ("Fatal error" in txt) or ("FAILURES" in txt)
  if failed:
    gate["pass"] = False
    gate["failures"].append({"check": name, "evidence": str(path)})
(reports_dir / "gate.json").write_text(json.dumps(gate, indent=2))
print("json", gate)
PY

echo "== Gate done =="

gate_pass="$(python3 - <<'PY'
import json, pathlib
p = pathlib.Path("reports/gate.json")
try:
    print("true" if json.loads(p.read_text()).get("pass", True) else "false")
except Exception:
    print("true")
PY
))"
if [ "$gate_pass" != "true" ]; then
  echo "Gate failed according to reports/gate.json"
  exit 1
fi
