import os, json, re, argparse
from tooling_requirements import REQ_DEV, OPTIONAL_DEV, OPTIONAL_ALLOW_PLUGIN

def read(path):
    return open(path, "r", encoding="utf-8", errors="ignore").read()

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("plugin_dir")
    ap.add_argument("reports_dir")
    args = ap.parse_args()

    plug = os.path.abspath(args.plugin_dir)
    rep = os.path.abspath(args.reports_dir)
    os.makedirs(rep, exist_ok=True)

    report = {
        "pass": True,
        "checks": []
    }

    # composer.json
    cpath = os.path.join(plug, "composer.json")
    if not os.path.exists(cpath):
        report["pass"] = False
        report["checks"].append({"name":"composer_json", "pass":False, "reason":"missing"})
    else:
        try:
            c = json.loads(read(cpath))
        except Exception as e:
            report["pass"] = False
            report["checks"].append({"name":"composer_json", "pass":False, "reason":f"invalid_json: {e}"})
            c = None

        if c:
            reqdev = c.get("require-dev", {}) or {}
            missing = [p for p in REQ_DEV.keys() if p not in reqdev]
            if missing:
                report["pass"] = False
                report["checks"].append({"name":"composer_require_dev", "pass":False, "missing":missing})
            else:
                report["checks"].append({"name":"composer_require_dev", "pass":True})

            # Optional: installer
            opt_missing = [p for p in OPTIONAL_DEV.keys() if p not in reqdev]
            allow_plugins = ((c.get("config") or {}).get("allow-plugins") or {})
            allow_ok = allow_plugins.get(OPTIONAL_ALLOW_PLUGIN) is True

            if opt_missing or not allow_ok:
                report["pass"] = False
                report["checks"].append({
                    "name":"composer_optional_installer",
                    "pass":False,
                    "missing_optional": opt_missing,
                    "allow_plugins_ok": allow_ok
                })
            else:
                report["checks"].append({"name":"composer_optional_installer", "pass":True})

    # phpcs.xml.dist
    ppath = os.path.join(plug, "phpcs.xml.dist")
    if not os.path.exists(ppath):
        report["pass"] = False
        report["checks"].append({"name":"phpcs_ruleset", "pass":False, "reason":"missing"})
    else:
        s = read(ppath)
        # quick sanity: references WordPress standards
        ok = ("WordPress-Core" in s) or ("WordPress" in s)
        if not ok:
            report["pass"] = False
            report["checks"].append({"name":"phpcs_ruleset", "pass":False, "reason":"does_not_reference_wordpress_standards"})
        else:
            report["checks"].append({"name":"phpcs_ruleset", "pass":True})

    # phpstan.neon.dist
    spath = os.path.join(plug, "phpstan.neon.dist")
    if not os.path.exists(spath):
        report["pass"] = False
        report["checks"].append({"name":"phpstan_config", "pass":False, "reason":"missing"})
    else:
        s = read(spath)
        ok = "vendor/szepeviktor/phpstan-wordpress/extension.neon" in s
        if not ok:
            report["pass"] = False
            report["checks"].append({"name":"phpstan_config", "pass":False, "reason":"missing_wp_extension_include"})
        else:
            report["checks"].append({"name":"phpstan_config", "pass":True})

    out = os.path.join(rep, "tooling.json")
    with open(out, "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2)

    print(json.dumps(report, indent=2))
    if not report["pass"]:
        raise SystemExit(2)

if __name__ == "__main__":
    main()
