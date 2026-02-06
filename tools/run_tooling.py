import os, json, argparse, subprocess, time

def run(cmd, cwd, timeout=1800):
    p = subprocess.run(cmd, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, timeout=timeout)
    return p.returncode, p.stdout

def write(path, s):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        f.write(s)

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("plugin_dir")
    ap.add_argument("reports_dir")
    args = ap.parse_args()

    plug = os.path.abspath(args.plugin_dir)
    rep = os.path.abspath(args.reports_dir)
    os.makedirs(rep, exist_ok=True)

    summary = {
        "timestamp": int(time.time()),
        "tools": {}
    }

    # Lint
    rc, out = run(["bash","-lc","composer run -q lint"], plug)
    write(os.path.join(rep, "php-lint.txt"), out)
    summary["tools"]["lint"] = {"rc": rc, "output": "php-lint.txt"}

    # PHPCS
    rc, out = run(["bash","-lc","composer run -q phpcs"], plug)
    write(os.path.join(rep, "phpcs.txt"), out)
    summary["tools"]["phpcs"] = {"rc": rc, "output": "phpcs.txt"}

    # PHPStan
    rc, out = run(["bash","-lc","composer run -q phpstan"], plug)
    write(os.path.join(rep, "phpstan.txt"), out)
    summary["tools"]["phpstan"] = {"rc": rc, "output": "phpstan.txt"}

    # composer validate (optional but useful)
    rc, out = run(["bash","-lc","composer validate --no-check-publish"], plug)
    write(os.path.join(rep, "composer-validate.txt"), out)
    summary["tools"]["composer_validate"] = {"rc": rc, "output": "composer-validate.txt"}

    write(os.path.join(rep, "tooling-run.json"), json.dumps(summary, indent=2))
    print(json.dumps(summary, indent=2))

if __name__ == "__main__":
    main()
