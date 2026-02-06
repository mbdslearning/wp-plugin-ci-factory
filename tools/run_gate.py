import os, sys, json, subprocess, argparse, shutil, re, textwrap, pathlib, time

def run(cmd, cwd, timeout=1800):
    p = subprocess.run(cmd, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, timeout=timeout)
    return p.returncode, p.stdout

def write(path, s):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    open(path, "w", encoding="utf-8").write(s)

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("plugin_dir")
    ap.add_argument("reports_dir")
    ap.add_argument("--wp-version", default="latest")
    ap.add_argument("--main-file", default="")
    args = ap.parse_args()

    plug = os.path.abspath(args.plugin_dir)
    rep = os.path.abspath(args.reports_dir)

    results = {"timestamp": int(time.time()), "checks": [], "summary": {"pass": True}}
    env = os.environ.copy()

    # Composer audit
    rc, out = run(["composer", "audit", "--no-interaction"], plug)
    write(os.path.join(rep, "composer-audit.txt"), out)
    results["checks"].append({"name":"composer_audit","rc":rc,"artifact":"composer-audit.txt"})
    if rc != 0:
        results["summary"]["pass"] = False

    # PHP lint
    rc, out = run(["bash","-lc","find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 -P4 php -l"], plug)
    write(os.path.join(rep, "php-lint.txt"), out)
    results["checks"].append({"name":"php_lint","rc":rc,"artifact":"php-lint.txt"})
    if rc != 0:
        results["summary"]["pass"] = False

    # PHPCS (assumes you will add phpcs.xml.dist if you want strict rules)
    rc, out = run(["bash","-lc","vendor/bin/phpcs -q --report=full || true"], plug)
    write(os.path.join(rep, "phpcs.txt"), out)
    # treat findings as failure if output contains ERROR/WARNING
    fail = bool(re.search(r"\bERROR\b|\bWARNING\b", out))
    results["checks"].append({"name":"phpcs_wpcs","rc": 1 if fail else 0,"artifact":"phpcs.txt"})
    if fail:
        results["summary"]["pass"] = False

    # PHPStan
    rc, out = run(["bash","-lc","vendor/bin/phpstan analyse --no-progress || true"], plug)
    write(os.path.join(rep, "phpstan.txt"), out)
    fail = ("ERROR" in out) or ("Found" in out and "errors" in out)
    results["checks"].append({"name":"phpstan","rc": 1 if fail else 0,"artifact":"phpstan.txt"})
    if fail:
        results["summary"]["pass"] = False

    # Semgrep (local)
    rc, out = run(["bash","-lc","vendor/bin/semgrep --config p/php --error --no-rewrite-rule-ids --metrics=off . || true"], plug)
    write(os.path.join(rep, "semgrep.txt"), out)
    fail = "ERROR" in out or "finding" in out.lower() or "Findings" in out
    results["checks"].append({"name":"semgrep","rc": 1 if fail else 0,"artifact":"semgrep.txt"})
    if fail:
        results["summary"]["pass"] = False

    # WP integration tests (if phpunit config exists)
    phpunit_cfg = os.path.join(plug, "phpunit.xml.dist")
    if os.path.exists(phpunit_cfg):
        # install wp tests (script expected in plugin)
        if os.path.exists(os.path.join(plug,"bin","install-wp-tests.sh")):
            os.chmod(os.path.join(plug,"bin","install-wp-tests.sh"), 0o755)
        # The install step is handled in workflow; here we just run phpunit
        rc, out = run(["bash","-lc","vendor/bin/phpunit --configuration phpunit.xml.dist || true"], plug)
        write(os.path.join(rep, "phpunit.txt"), out)
        fail = ("FAILURES" in out) or ("ERRORS" in out)
        results["checks"].append({"name":"phpunit_wp_integration","rc": 1 if fail else 0,"artifact":"phpunit.txt"})
        if fail:
            results["summary"]["pass"] = False
    else:
        results["checks"].append({"name":"phpunit_wp_integration","rc":0,"skipped":True,"reason":"phpunit.xml.dist missing"})

    write(os.path.join(rep, "gate.json"), json.dumps(results, indent=2))
    print(json.dumps(results, indent=2))

if __name__ == "__main__":
    main()
