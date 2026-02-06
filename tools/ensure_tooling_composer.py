import os, json, argparse
from tooling_requirements import REQ_DEV, OPTIONAL_DEV, OPTIONAL_ALLOW_PLUGIN

TEMPLATE = "templates/composer.devtools.json"

def load_json(path):
    return json.loads(open(path, "r", encoding="utf-8").read())

def save_json(path, data):
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2)
        f.write("\n")

def deep_get(d, *keys, default=None):
    cur = d
    for k in keys:
        if not isinstance(cur, dict) or k not in cur:
            return default
        cur = cur[k]
    return cur

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("plugin_dir")
    args = ap.parse_args()

    plugin_dir = os.path.abspath(args.plugin_dir)
    path = os.path.join(plugin_dir, "composer.json")
    changed = False

    if not os.path.exists(path):
        data = load_json(os.path.join(os.getcwd(), TEMPLATE))
        save_json(path, data)
        print("created composer.json")
        return

    data = load_json(path)

    data.setdefault("require", {})
    data.setdefault("require-dev", {})
    data.setdefault("scripts", {})
    data.setdefault("config", {})
    data["config"].setdefault("allow-plugins", {})

    # Ensure required dev deps
    for pkg, ver in REQ_DEV.items():
        if pkg not in data["require-dev"]:
            data["require-dev"][pkg] = ver
            changed = True

    # Ensure optional dev deps
    for pkg, ver in OPTIONAL_DEV.items():
        if pkg not in data["require-dev"]:
            data["require-dev"][pkg] = ver
            changed = True

    # Ensure allow-plugins for the optional installer
    allow_plugins = data["config"]["allow-plugins"]
    if allow_plugins.get(OPTIONAL_ALLOW_PLUGIN) is not True:
        allow_plugins[OPTIONAL_ALLOW_PLUGIN] = True
        changed = True

    # Ensure scripts exist (do not overwrite if user customized)
    data["scripts"].setdefault(
        "lint",
        "find . -type f -name \"*.php\" -not -path \"./vendor/*\" -print0 | xargs -0 -n1 -P4 php -l"
    )
    data["scripts"].setdefault("phpcs", "phpcs -q --standard=phpcs.xml.dist")
    data["scripts"].setdefault("phpcbf", "phpcbf --standard=phpcs.xml.dist")
    data["scripts"].setdefault("phpstan", "phpstan analyse --no-progress --configuration=phpstan.neon.dist")

    if changed:
        save_json(path, data)
        print("updated composer.json")
    else:
        print("no_change")

if __name__ == "__main__":
    main()
