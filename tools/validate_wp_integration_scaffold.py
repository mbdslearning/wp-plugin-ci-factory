import os, json, argparse, re, stat

def read(p):
    with open(p, "r", encoding="utf-8", errors="ignore") as f:
        return f.read()

def is_executable(path: str) -> bool:
    try:
        st = os.stat(path)
    except FileNotFoundError:
        return False
    return bool(st.st_mode & stat.S_IXUSR)

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("plugin_dir")
    ap.add_argument("reports_dir")
    ap.add_argument("--main-file", required=True)
    args = ap.parse_args()

    plug = os.path.abspath(args.plugin_dir)
    rep = os.path.abspath(args.reports_dir)
    os.makedirs(rep, exist_ok=True)

    main_file = args.main_file.strip().lstrip("/")

    report = {"pass": True, "checks": []}
    phpunit_xml = os.path.join(plug, "phpunit.xml.dist")
    bootstrap = os.path.join(plug, "tests", "bootstrap.php")
    installer = os.path.join(plug, "bin", "install-wp-tests.sh")

    if not os.path.exists(phpunit_xml):
        report["pass"] = False
        report["checks"].append({"name":"phpunit_xml", "pass":False, "reason":"missing"})
    else:
        s = read(phpunit_xml)
        ok = "tests/bootstrap.php" in s
        report["checks"].append({"name":"phpunit_xml", "pass":ok, "reason":"" if ok else "does_not_reference_tests_bootstrap"})
        report["pass"] = report["pass"] and ok

    if not os.path.exists(bootstrap):
        report["pass"] = False
        report["checks"].append({"name":"tests_bootstrap", "pass":False, "reason":"missing"})
    else:
        s = read(bootstrap)
        rx = re.compile(r"require\s+dirname\(__DIR__\)\s*\.\s*'/" + re.escape(main_file) + r"'\s*;")
        ok = rx.search(s) is not None
        report["checks"].append({"name":"tests_bootstrap", "pass":ok, "reason":"" if ok else "does_not_require_main_plugin_file_correctly"})
        report["pass"] = report["pass"] and ok

    if not os.path.exists(installer):
        report["pass"] = False
        report["checks"].append({"name":"install_wp_tests_sh", "pass":False, "reason":"missing"})
    else:
        ok = is_executable(installer)
        report["checks"].append({"name":"install_wp_tests_sh_executable", "pass":ok, "reason":"" if ok else "not_executable"})
        report["pass"] = report["pass"] and ok

    out = os.path.join(rep, "wp-integration.json")
    with open(out, "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2)

    print(json.dumps(report, indent=2))
    if not report["pass"]:
        raise SystemExit(2)

if __name__ == "__main__":
    main()
