import os, json, argparse, subprocess, time

def run(cmd, cwd, timeout=3600, env=None):
    p = subprocess.run(cmd, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, timeout=timeout, env=env)
    return p.returncode, p.stdout

def write(path, s):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        f.write(s)

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("plugin_dir")
    ap.add_argument("reports_dir")
    ap.add_argument("--wp-version", default="latest")
    ap.add_argument("--db-name", default="wordpress_test")
    ap.add_argument("--db-user", default="wp")
    ap.add_argument("--db-pass", default="wp")
    ap.add_argument("--db-host", default="127.0.0.1")
    args = ap.parse_args()

    plug = os.path.abspath(args.plugin_dir)
    rep = os.path.abspath(args.reports_dir)
    os.makedirs(rep, exist_ok=True)

    summary = {"timestamp": int(time.time()), "install": {}, "phpunit": {}}

    installer = os.path.join(plug, "bin", "install-wp-tests.sh")
    if os.path.exists(installer):
        os.chmod(installer, 0o755)
        rc, out = run(
            ["bash", "-lc", f"bin/install-wp-tests.sh {args.db_name} {args.db_user} {args.db_pass} {args.db_host} {args.wp_version} true"],
            cwd=plug
        )
        write(os.path.join(rep, "wp-tests-install.txt"), out)
        summary["install"] = {"rc": rc, "output": "wp-tests-install.txt"}
    else:
        write(os.path.join(rep, "wp-tests-install.txt"), "Missing bin/install-wp-tests.sh\n")
        summary["install"] = {"rc": 2, "output": "wp-tests-install.txt", "error": "missing_installer"}

    # Run PHPUnit if phpunit.xml.dist exists
    if os.path.exists(os.path.join(plug, "phpunit.xml.dist")):
        rc, out = run(["bash", "-lc", "vendor/bin/phpunit --configuration phpunit.xml.dist"], cwd=plug)
        write(os.path.join(rep, "phpunit.txt"), out)
        summary["phpunit"] = {"rc": rc, "output": "phpunit.txt"}
    else:
        write(os.path.join(rep, "phpunit.txt"), "Missing phpunit.xml.dist\n")
        summary["phpunit"] = {"rc": 2, "output": "phpunit.txt", "error": "missing_phpunit_config"}

    write(os.path.join(rep, "wp-integration-run.json"), json.dumps(summary, indent=2))
    print(json.dumps(summary, indent=2))

    # Fail if installer or phpunit failed
    if summary["install"]["rc"] != 0 or summary["phpunit"]["rc"] != 0:
        raise SystemExit(1)

if __name__ == "__main__":
    main()
