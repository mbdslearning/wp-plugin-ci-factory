#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${DB_NAME:-wordpress_test}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
WP_VERSION="${WP_VERSION:-latest}"
WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

mkdir -p "$WP_TESTS_DIR" "$WP_CORE_DIR"

echo "Installing WP test suite (WP_VERSION=$WP_VERSION)..."

# Download WP core
if [ "$WP_VERSION" = "latest" ]; then
  WP_TARBALL_URL="https://wordpress.org/latest.tar.gz"
else
  WP_TARBALL_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
fi

curl -sSL "$WP_TARBALL_URL" | tar -xz -C /tmp
rm -rf "$WP_CORE_DIR
mv /tmp/wordpress "$WP_CORE_DIR"

# Download tests
if [ ! -d "$WP_TESTS_DIR/includes" ]; then
  svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/ "$WP_TESTS_DIR/includes"
  svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/data/ "$WP_TESTS_DIR/data"
fi

# Create wp-tests-config.php
if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
  cp "$WP_TESTS_DIR/includes/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s/yourusernamehere/$DB_USER/'" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s|localhost|$DB_HOST:$DB_PORT|" "$WP_TESTS_DIR/wp-tests-config.php"
fi

mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS `$DB_NAME`;" || true
echo "WP tests installed."
