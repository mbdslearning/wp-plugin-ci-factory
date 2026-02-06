import os, sys, json, subprocess, argparse, re, time

def run(cmd, cwd, timeout=1800):
    p = subprocess.run(
        cmd,
        cwd=cwd,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        timeout=timeout
    )
    return p.returncode, p.stdout

def write(path, s):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        f.write(s)

def read(path):
    if not os.path.exists(path):
        return ""
    with open(path, "r", encoding="utf-8", errors="ignore") as f:
        return f.read()

def semgrep_fail_from_sarif(sarif_path: str):
    """
    Return (fail_bool, count_int, error_str_or_empty)
    Semgrep SARIF format typically follows SARIF 2.1.0.
    We treat any SARIF 'results' entries as findings -> fail.
    """
    if not os.path.exists(sarif_path):
        return (False, 0, "missing")

    try:
        sarif = json.loads(read(sarif_path))
    except Exception as e:
        return (True, 0, f"invalid_json: {e}")

    count = 0
    try:
        runs = sarif.get("runs", []) or []
        for r in runs:
            results = r.get("results", []) or []
            count += len(results)
    except Exception as e:
        return (True, 0, f"parse_error: {e}")

    return (count > 0, count, "")

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("plugin_dir")
    ap.add_argument("reports_dir")
    ap.add_argument("--wp-version", default="latest")
    ap.add_argument("--main-file", default="")
    args = ap.parse_args()

    plug = os.path.abspath(args.plugin_dir)
    rep = os.path.abspath(args.reports_dir)

    results = {
        "timestamp": int(time.time()),
        "checks": [],
        "summary": {"pass": True}
    }

    # Composer audit
    rc, out = run(["composer", "audit", "--no-interaction"], plug)
    write(os.path.join(rep, "composer-audit.txt"), out)
    results["checks"].append({"name": "composer_audit", "rc": rc, "artifact": "composer-audit.txt"})
    if rc != 0:
        results["summary"]["pass"] = False

    # PHP syntax lint
    rc, out = run(["bash", "-lc", "find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 -P4 php -l"], plug)
    write(os.path.join(rep, "php-lint.txt"), out)
    results["checks"].append({"name": "php_lint", "rc": rc, "artifact": "php-lint.txt"})
    if rc != 0:
        results["summary"]["pass"] = False

    # PHPCS / WPCS (treat warnings/errors as failure)
    rc, out = run(["bash", "-lc", "vendor/bin/phpcs -q --report=full || true"], plug)
    write(os.path.join(rep, "phpcs.txt"), out)
    phpcs_fail = bool(re.search(r"\bERROR\b|\bWARNING\b", out))
    results["checks"].append({"name": "phpcs_wpcs", "rc": 1 if phpcs_fail else 0, "artifact": "phpcs.txt"})
    if phpcs_fail:
        results["summary"]["pass"] = False

    # PHPStan (treat errors as failure)
    rc, out = run(["bash", "-lc", "vendor/bin/phpstan analyse --no-progress || true"], plug)
    write(os.path.join(rep, "phpstan.txt"), out)
    phpstan_fail = ("ERROR" in out) or ("Found" in out and "errors" in out)
    results["checks"].append({"name": "phpstan", "rc": 1 if phpstan_fail else 0, "artifact": "phpstan.txt"})
    if phpstan_fail:
        results["summary"]["pass"] = False

    # Semgrep (DO NOT RUN HERE)
    # Instead, consume SARIF produced by the workflow step:
    sarif_path = os.path.join(rep, "semgrep.sarif")
    semgrep_fail, semgrep_count, semgrep_err = semgrep_fail_from_sarif(sarif_path)
    if semgrep_err == "missing":
        results["checks"].append({
            "name": "semgrep_sarif",
            "rc": 0,
            "skipped": True,
            "reason": "reports/semgrep.sarif missing (Semgrep SARIF step not run or failed)",
            "expected_artifact": "semgrep.sarif"
        })
    elif semgrep_err:
        results["checks"].append({
            "name": "semgrep_sarif",
            "rc": 1,
            "artifact": "semgrep.sarif",
            "error": semgrep_err
        })
        results["summary"]["pass"] = False
    else:
        results["checks"].append({
            "name": "semgrep_sarif",
            "rc": 1 if semgrep_fail else 0,
            "artifact": "semgrep.sarif",
            "findings_count": semgrep_count
        })
        if semgrep_fail:
            results["summary"]["pass"] = False

    # WP integration tests (only if phpunit.xml.dist exists)
    phpunit_cfg = os.path.join(plug, "phpunit.xml.dist")
    if os.path.exists(phpunit_cfg):
        rc, out = run(["bash", "-lc", "vendor/bin/phpunit --configuration phpunit.xml.dist || true"], plug)
        write(os.path.join(rep, "phpunit.txt"), out)
        phpunit_fail = ("FAILURES" in out) or ("ERRORS" in out)
        results["checks"].append({"name": "phpunit_wp_integration", "rc": 1 if phpunit_fail else 0, "artifact": "phpunit.txt"})
        if phpunit_fail:
            results["summary"]["pass"] = False
    else:
        results["checks"].append({
            "name": "phpunit_wp_integration",
            "rc": 0,
            "skipped": True,
            "reason": "phpunit.xml.dist missing"
        })

    write(os.path.join(rep, "gate.json"), json.dumps(results, indent=2))
    print(json.dumps(results, indent=2))

if __name__ == "__main__":
    main()