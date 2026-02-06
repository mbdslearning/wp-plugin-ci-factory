import os, json, sys

plugin_dir = sys.argv[1]
path = os.path.join(plugin_dir, "composer.json")

default = {
  "name": "ci/wp-plugin",
  "type": "project",
  "require": {},
  "require-dev": {
    "phpunit/phpunit": "^9.6",
    "yoast/phpunit-polyfills": "^3.0",
    "squizlabs/php_codesniffer": "^3.10",
    "wp-coding-standards/wpcs": "^3.1",
    "phpstan/phpstan": "^1.11",
    "szepeviktor/phpstan-wordpress": "^1.3",
    "vimeo/psalm": "^5.26",
    "semgrep/semgrep": "^1.94"
  },
  "scripts": {
    "lint": "find . -type f -name \"*.php\" -not -path \"./vendor/*\" -print0 | xargs -0 -n1 -P4 php -l",
    "phpcs": "phpcs -q --report=full",
    "phpstan": "phpstan analyse --no-progress",
    "psalm": "psalm --no-progress",
    "audit": "composer audit --no-interaction"
  },
  "config": {
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": True
    }
  }
}

if not os.path.exists(path):
    with open(path, "w", encoding="utf-8") as f:
        json.dump(default, f, indent=2)
