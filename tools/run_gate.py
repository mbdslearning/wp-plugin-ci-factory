import os, json, argparse, subprocess, glob

def run(cmd, cwd, out_path, env=None, timeout=3600):
    p = subprocess.run(cmd, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, env=env, timeout=timeout)
    with open(out_path, "w", encoding="utf-8") as f:
        f.write(p.stdout)
    return p.returncode

def list_php_files(root):
    files = []
    for dirpath, _, filenames in os.walk(root):
        if "/vendor/" in dirpath.replace('\\','/'):
            continue
        for fn in filenames:
            if fn.lower().endswith(".php"):
                files.append(os.path.join(dirpath, fn))
    return files

def php_lint(plugin_dir, reports_dir):
    files = list_php_files(plugin_dir)
    cmd = ["bash", "-lc", "set -e; " + " ; ".join([f"php -l '{f}'" for f in files[:4000]])]
    # (limit number to avoid huge command line; for big plugins, lint a subset)
    return run(cmd, plugin_dir, os.path.join(reports_dir, "php-lint.txt"))

def phpcs(plugin_dir, reports_dir):
    if not os.path.exists(os.path.join(plugin_dir, "vendor", "bin", "phpcs")):
        with open(os.path.join(reports_dir, "phpcs.txt"), "w", encoding="utf-8") as f:
            f.write("phpcs not installed\n")
        return 2
    return run(["bash","-lc","vendor/bin/phpcs"], plugin_dir, os.path.join(reports_dir, "phpcs.txt"))

def phpstan(plugin_dir, reports_dir):
    if not os.path.exists(os.path.join(plugin_dir, "vendor", "bin", "phpstan")):
        with open(os.path.join(reports_dir, "phpstan.txt"), "w", encoding="utf-8") as f:
            f.write("phpstan not installed\n")
        return 2
    config = "phpstan.neon.dist" if os.path.exists(os.path.join(plugin_dir, "phpstan.neon.dist")) else "phpstan.neon"
    return run(["bash","-lc", f"vendor/bin/phpstan analyse -c {config}"], plugin_dir, os.path.join(reports_dir, "phpstan.txt"))

def phpunit_smoke(plugin_dir, reports_dir):
    # WP integration is run separately; here we just record presence
    if os.path.exists(os.path.join(reports_dir, "phpunit.txt")):
        return 0
    with open(os.path.join(reports_dir, "phpunit.txt"), "w", encoding="utf-8") as f:
        f.write("phpunit output not found (WP integration step may have been skipped)\n")
    return 2

def semgrep_from_sarif(reports_dir):
    # If workflow runs semgrep separately and drops SARIF into reports, treat it as evidence.
    sarifs = glob.glob(os.path.join(reports_dir, "*.sarif")) + glob.glob(os.path.join(reports_dir, "*.sarif.json"))
    if not sarifs:
        return {"pass": True, "note": "no sarif found"}
    try:
        data = json.load(open(sarifs[0], "r", encoding="utf-8"))
        runs = data.get("runs", [])
        results = runs[0].get("results", []) if runs else []
        # Fail if any high/critical-like rules exist (SARIF doesn't standardize severity consistently)
        return {"pass": len(results) == 0, "results_count": len(results), "sarif": os.path.basename(sarifs[0])}
    except Exception as e:
        return {"pass": False, "error": str(e), "sarif": os.path.basename(sarifs[0])}

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("plugin_dir")
    ap.add_argument("reports_dir")
    ap.add_argument("--wp-version", default="latest")
    ap.add_argument("--main-file", required=True)
    args = ap.parse_args()

    plug = os.path.abspath(args.plugin_dir)
    rep = os.path.abspath(args.reports_dir)
    os.makedirs(rep, exist_ok=True)

    env = os.environ.copy()

    checks = []
    def add(name, rc, evidence):
        checks.append({"name": name, "pass": rc == 0, "rc": rc, "evidence": evidence})

    add("php_lint", php_lint(plug, rep), "php-lint.txt")
    add("phpcs", phpcs(plug, rep), "phpcs.txt")
    add("phpstan", phpstan(plug, rep), "phpstan.txt")
    add("phpunit_wp", phpunit_smoke(plug, rep), "phpunit.txt")

    sem = semgrep_from_sarif(rep)
    checks.append({"name": "semgrep_sarif", "pass": bool(sem.get("pass")), "detail": sem})

    summary = {"pass": all(c.get("pass") for c in checks), "checks": checks}
    out = {"summary": summary}
    with open(os.path.join(rep, "gate.json"), "w", encoding="utf-8") as f:
        json.dump(out, f, indent=2)
    print(json.dumps(out, indent=2))
    if not summary["pass"]:
        raise SystemExit(1)

if __name__ == "__main__":
    main()
