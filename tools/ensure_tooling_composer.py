import os, sys, json, textwrap

COMPOSER_JSON = {
  "name": "ci-factory/plugin-dev-tooling",
  "description": "Dev-only tooling for WP plugin CI (not required at runtime).",
  "type": "project",
  "license": "proprietary",
  "require": {},
  "require-dev": {
    "phpunit/phpunit": "^10.5",
    "squizlabs/php_codesniffer": "^3.10",
    "wp-coding-standards/wpcs": "^3.0",
    "phpstan/phpstan": "^1.11",
    "szepeviktor/phpstan-wordpress": "^1.3",
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0"
  },
  "config": {
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": true
    },
    "sort-packages": true
  },
  "scripts": {
    "lint": "php -d display_errors=1 -l $(find . -type f -name '*.php' -not -path './vendor/*')",
    "phpcs": "phpcs -q",
    "phpstan": "phpstan analyse"
  }
}

PHPCS_XML = """<?xml version=\"1.0\"?>
<ruleset name="WP Plugin CI">
  <description>WPCS ruleset for CI</description>

  <config name="installed_paths" value="vendor/wp-coding-standards/wpcs"/>
  <arg name="extensions" value="php"/>
  <arg name="colors"/>
  <arg value="ps"/>

  <exclude-pattern>vendor/*</exclude-pattern>
  <exclude-pattern>node_modules/*</exclude-pattern>
  <exclude-pattern>tests/*</exclude-pattern>

  <rule ref="WordPress-Core"/>
  <rule ref="WordPress-Extra"/>
  <rule ref="WordPress-Docs"/>
</ruleset>
"""

PHPSTAN_NEON = """parameters:
  level: 6
  paths:
    - .
  excludePaths:
    - vendor
    - node_modules
    - tests
  scanFiles: []
  tmpDir: /tmp/phpstan
includes:
  - vendor/szepeviktor/phpstan-wordpress/extension.neon
"""

def ensure_file(path: str, content: str) -> None:
    if os.path.exists(path):
        return
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        f.write(content)

def main():
    plugin_dir = sys.argv[1]
    comp = os.path.join(plugin_dir, "composer.json")
    if not os.path.exists(comp):
        with open(comp, "w", encoding="utf-8") as f:
            json.dump(COMPOSER_JSON, f, indent=2)
            f.write("\n")

    ensure_file(os.path.join(plugin_dir, "phpcs.xml.dist"), PHPCS_XML)
    ensure_file(os.path.join(plugin_dir, "phpstan.neon.dist"), PHPSTAN_NEON)
    # phpunit.xml.dist and tests/bootstrap.php are handled by ensure_wp_integration_scaffold.py

if __name__ == "__main__":
    main()
