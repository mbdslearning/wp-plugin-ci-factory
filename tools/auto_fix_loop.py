import os, sys, json, argparse, subprocess, time, re, textwrap

def run(cmd, cwd, timeout=1800):
    p = subprocess.run(cmd, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, timeout=timeout)
    return p.returncode, p.stdout

def read(p):
    return open(p, "r", encoding="utf-8", errors="ignore").read() if os.path.exists(p) else ""

def write(p, s):
    os.makedirs(os.path.dirname(p), exist_ok=True)
    open(p, "w", encoding="utf-8").write(s)

def call_openai(prompt: str) -> str:
    # Minimal REST call (no extra deps). Expects OPENAI_API_KEY present.
    import urllib.request, json
    key = os.environ.get("OPENAI_API_KEY","")
    if not key:
        raise SystemExit("OPENAI_API_KEY missing")
    body = {
      "model": "gpt-5.2-thinking",
      "input": prompt
    }
    req = urllib.request.Request(
      "https://api.openai.com/v1/responses",
      data=json.dumps(body).encode("utf-8"),
      headers={
        "Authorization": f"Bearer {key}",
        "Content-Type": "application/json"
      },
      method="POST"
    )
    with urllib.request.urlopen(req, timeout=120) as resp:
        data = json.loads(resp.read().decode("utf-8"))
    # Extract text output
    parts = []
    for item in data.get("output", []):
        for c in item.get("content", []):
            if c.get("type") == "output_text":
                parts.append(c.get("text",""))
    return "\n".join(parts).strip()

def ensure_tooling(plugin_dir: str):
    run(["python3", "tools/ensure_tooling_composer.py", plugin_dir], os.getcwd())
    run(["python3", "tools/ensure_tooling_configs.py", plugin_dir], os.getcwd())

def validate_tooling(plugin_dir: str, reports_dir: str):
    run(["python3", "tools/validate_tooling_setup.py", plugin_dir, reports_dir], os.getcwd())

def run_tooling(plugin_dir: str, reports_dir: str) -> None:
    # Writes reports/tooling-run.json + raw outputs
    run(["python3", "tools/run_tooling.py", plugin_dir, reports_dir], os.getcwd())

def apply_patch(plugin_dir: str, patch_text: str) -> None:
    patch_path = os.path.join(plugin_dir, ".ci_autofix.patch")
    write(patch_path, patch_text + "\n")
    rc, out = run(["bash","-lc", f"git apply --whitespace=fix {patch_path}"], plugin_dir)
    if rc != 0:
        raise RuntimeError("Failed to apply patch:\n" + out)

def gate(plugin_dir: str, reports_dir: str, wp_version: str, main_file: str) -> dict:
    rc, out = run(["python3","tools/run_gate.py", plugin_dir, reports_dir, "--wp-version", wp_version, "--main-file", main_file], os.getcwd())
    return json.loads(read(os.path.join(reports_dir, "gate.json")))

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--plugin-dir", required=True)
    ap.add_argument("--reports-dir", required=True)
    ap.add_argument("--max-it", required=True)
    ap.add_argument("--wp-version", default="latest")
    ap.add_argument("--main-file", default="")
    args = ap.parse_args()

    plug = os.path.abspath(args.plugin_dir)
    rep = os.path.abspath(args.reports_dir)
    max_it = int(args.max_it)

    # init git so we can apply patches cleanly and commit
    if not os.path.exists(os.path.join(plug, ".git")):
        run(["bash","-lc","git init -q"], plug)
        run(["bash","-lc","git add -A && git commit -qm 'ci: initial import' || true"], plug)

    for i in range(1, max_it+1):
        ensure_tooling(plug)
        validate_tooling(plug, rep)
        run_tooling(plug, rep)

        g = json.loads(read(os.path.join(rep, "gate.json"))) if os.path.exists(os.path.join(rep,"gate.json")) else gate(plug, rep, args.wp_version, args.main_file)
        if g.get("summary",{}).get("pass") is True:
            print(f"Gate already passing at iteration {i-1}.")
            return

        # Build a compact “evidence bundle”
        tooling_json = read(os.path.join(rep, "tooling.json"))[:12000]
        tooling_run = read(os.path.join(rep, "tooling-run.json"))[:12000]
        composer_validate = read(os.path.join(rep, "composer-validate.txt"))[:12000]
        
        lint = read(os.path.join(rep, "php-lint.txt"))[:12000]
        phpcs = read(os.path.join(rep, "phpcs.txt"))[:12000]
        phpstan = read(os.path.join(rep, "phpstan.txt"))[:12000]
        phpunit = read(os.path.join(rep, "phpunit.txt"))[:12000]
        semgrep = read(os.path.join(rep, "semgrep.txt"))[:12000]

        prompt = f"""
You are a senior WordPress plugin engineer. Produce a SINGLE unified diff patch that fixes issues.
Rules:
- Output ONLY a unified diff (git apply compatible). No commentary.
- Prefer minimal safe changes.
- Do not add new dependencies unless necessary.
- Preserve backwards compatibility and production readiness.
- Fix security issues first.

CI evidence:

Tooling setup validation (tooling.json):
{tooling_json}

Tooling run summary (tooling-run.json):
{tooling_run}

Composer validate:
{composer_validate}

PHP lint:
{lint}

PHPCS:
{phpcs}

PHPStan:
{phpstan}

PHPUnit:
{phpunit}

Semgrep:
{semgrep}

Repo structure: this is a WordPress plugin. Main plugin file: {args.main_file}
"""

        patch = call_openai(prompt)
        if "diff --git" not in patch:
            raise RuntimeError("Model did not return a unified diff.")

        apply_patch(plug, patch)
        # commit
        run(["bash","-lc", f"git add -A && git commit -m 'ci: autofix iteration {i}' || true"], plug)

        # re-run gate
        g2 = gate(plug, rep, args.wp_version, args.main_file)
        if g2.get("summary",{}).get("pass") is True:
            print(f"Gate passing after iteration {i}.")
            return

    print(f"Reached max iterations ({max_it}) without fully passing.")
    sys.exit(1)

if __name__ == "__main__":
    main()
