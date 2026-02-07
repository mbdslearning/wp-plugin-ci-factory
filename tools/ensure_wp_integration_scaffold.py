import os, argparse, stat, re

PHPUNIT_XML = """<?xml version="1.0"?>
<phpunit
  bootstrap="tests/bootstrap.php"
  colors="true"
  verbose="true"
>
  <testsuites>
    <testsuite name="WordPress Plugin Integration Tests">
      <directory suffix=".php">./tests</directory>
    </testsuite>
  </testsuites>

  <php>
    <env name="WP_TESTS_DIR" value="/tmp/wordpress-tests-lib"/>
    <env name="WP_CORE_DIR" value="/tmp/wordpress"/>
    <env name="WP_DEBUG" value="0"/>
    <env name="SCRIPT_DEBUG" value="0"/>
  </php>
</phpunit>
"""

INSTALL_WP_TESTS_SH = r"""#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${1:-wordpress_test}"
DB_USER="${2:-root}"
DB_PASS="${3:-}"
DB_HOST="${4:-localhost}"
WP_VERSION="${5:-latest}"
SKIP_DB_CREATE="${6:-false}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

download() {
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL "$1" -o "$2"
  elif command -v wget >/dev/null 2>&1; then
    wget -qO "$2" "$1"
  else
    echo "Error: curl or wget is required." >&2
    exit 1
  fi
}

install_wp_core() {
  mkdir -p "${WP_CORE_DIR}"
  if [ ! -f "${WP_CORE_DIR}/wp-load.php" ]; then
    echo "Downloading WordPress core (${WP_VERSION}) to ${WP_CORE_DIR}"
    if [ "${WP_VERSION}" = "latest" ]; then
      TMP_ZIP="/tmp/wordpress-latest.zip"
      download "https://wordpress.org/latest.zip" "${TMP_ZIP}"
      rm -rf "${WP_CORE_DIR}"
      unzip -q "${TMP_ZIP}" -d /tmp
      mv /tmp/wordpress "${WP_CORE_DIR}"
    else
      TMP_ZIP="/tmp/wordpress-${WP_VERSION}.zip"
      download "https://wordpress.org/wordpress-${WP_VERSION}.zip" "${TMP_ZIP}"
      rm -rf "${WP_CORE_DIR}"
      unzip -q "${TMP_ZIP}" -d /tmp
      mv /tmp/wordpress "${WP_CORE_DIR}"
    fi
  fi
}

install_wp_tests() {
  mkdir -p "${WP_TESTS_DIR}"
  if [ ! -d "${WP_TESTS_DIR}/includes" ]; then
    echo "Installing WP PHPUnit test library to ${WP_TESTS_DIR}"
    svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/ "${WP_TESTS_DIR}/includes"
    svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/data/ "${WP_TESTS_DIR}/data"
    svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/wp-tests-config-sample.php "${WP_TESTS_DIR}/wp-tests-config-sample.php"
  fi

  if [ ! -f "${WP_TESTS_DIR}/wp-tests-config.php" ]; then
    echo "Creating wp-tests-config.php"
    cp "${WP_TESTS_DIR}/wp-tests-config-sample.php" "${WP_TESTS_DIR}/wp-tests-config.php"

    sed -i.bak "s/youremptytestdbnamehere/${DB_NAME}/" "${WP_TESTS_DIR}/wp-tests-config.php"
    sed -i.bak "s/yourusernamehere/${DB_USER}/" "${WP_TESTS_DIR}/wp-tests-config.php"
    sed -i.bak "s/yourpasswordhere/${DB_PASS}/" "${WP_TESTS_DIR}/wp-tests-config.php"
    sed -i.bak "s|localhost|${DB_HOST}|" "${WP_TESTS_DIR}/wp-tests-config.php"
    rm -f "${WP_TESTS_DIR}/wp-tests-config.php.bak"

    if ! grep -q "WP_CORE_DIR" "${WP_TESTS_DIR}/wp-tests-config.php"; then
      echo "define( 'WP_CORE_DIR', '${WP_CORE_DIR}' );" >> "${WP_TESTS_DIR}/wp-tests-config.php"
    fi
  fi
}

create_db() {
  if [ "${SKIP_DB_CREATE}" = "true" ]; then
    echo "Skipping DB creation (skip-db-create=true)"
    return
  fi

  echo "Creating database ${DB_NAME} (if not exists)"
  php -r "
  \$mysqli = @new mysqli('${DB_HOST}', '${DB_USER}', '${DB_PASS}');
  if (\$mysqli->connect_error) { fwrite(STDERR, \$mysqli->connect_error . PHP_EOL); exit(1); }
  \$mysqli->query('CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`');
  "
}

install_wp_core
install_wp_tests
create_db
echo "Done."
"""

TEST_PLUGIN_LOADS = """<?php

class Test_Plugin_Loads extends WP_UnitTestCase {
    public function test_plugin_loaded() {
        $this->assertTrue( defined('ABSPATH') );
        $this->assertTrue(true);
    }
}
"""

BOOTSTRAP_TEMPLATE = """<?php
/**
 * PHPUnit bootstrap for WordPress integration tests.
 */

$_tests_dir = getenv('WP_TESTS_DIR');
if (! $_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

if (getenv('WP_DEBUG') === '1') {
    if (! defined('WP_DEBUG')) define('WP_DEBUG', true);
    if (! defined('WP_DEBUG_DISPLAY')) define('WP_DEBUG_DISPLAY', true);
    if (! defined('WP_DEBUG_LOG')) define('WP_DEBUG_LOG', true);
}
if (getenv('SCRIPT_DEBUG') === '1') {
    if (! defined('SCRIPT_DEBUG')) define('SCRIPT_DEBUG', true);
}

function _manually_load_plugin() {
    require dirname(__DIR__) . '/{MAIN_FILE}';
}
tests_add_filter('muplugins_loaded', '_manually_load_plugin');

require $_tests_dir . '/includes/bootstrap.php';
"""

def write_if_missing(path: str, content: str) -> bool:
    if os.path.exists(path):
        return False
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        f.write(content)
    return True

def ensure_executable(path: str) -> bool:
    if not os.path.exists(path):
        return False
    st = os.stat(path)
    if st.st_mode & stat.S_IXUSR:
        return False
    os.chmod(path, st.st_mode | stat.S_IXUSR | stat.S_IXGRP | stat.S_IXOTH)
    return True

def has_any_tests(tests_dir: str) -> bool:
    if not os.path.isdir(tests_dir):
        return False
    for fn in os.listdir(tests_dir):
        if fn.endswith(".php") and fn != "bootstrap.php":
            return True
    return False

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("plugin_dir")
    ap.add_argument("--main-file", required=True)
    args = ap.parse_args()

    plug = os.path.abspath(args.plugin_dir)
    main_file = args.main_file.strip().lstrip("/")

    changed = False
    changed |= write_if_missing(os.path.join(plug, "phpunit.xml.dist"), PHPUNIT_XML)
    changed |= write_if_missing(os.path.join(plug, "tests", "bootstrap.php"), BOOTSTRAP_TEMPLATE.replace("{MAIN_FILE}", main_file))
    inst = os.path.join(plug, "bin", "install-wp-tests.sh")
    changed |= write_if_missing(inst, INSTALL_WP_TESTS_SH)
    changed |= ensure_executable(inst)

    if not has_any_tests(os.path.join(plug, "tests")):
        changed |= write_if_missing(os.path.join(plug, "tests", "test-plugin-loads.php"), TEST_PLUGIN_LOADS)

    print("changed" if changed else "no_change")

if __name__ == "__main__":
    main()
