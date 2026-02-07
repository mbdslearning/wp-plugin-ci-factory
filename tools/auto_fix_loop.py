import os, argparse, json, subprocess, time, re
import requests

def run(cmd, cwd, timeout=3600):
    p = subprocess.run(cmd, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, timeout=timeout)
    return p.returncode, p.stdout

def read(path):
    if not os.path.exists(path):
        return ""
    with open(path, "r", encoding="utf-8", errors="ignore") as f:
        return f.read()

def read_clip(path, limit=12000):
    return read(path)[:limit]

def write(path, s):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        f.write(s)

def apply_unified_diff(plugin_dir: str, diff_text: str) -> None:
    # Apply patch using git apply-like behavior (requires plugin_dir is a git worktree? not necessarily).
    # We implement a minimal "applypatch" via `patch -p1` with working dir plugin_dir.
    p = subprocess.run(["bash","-lc","patch -p1 --forward --batch"], cwd=plugin_dir, input=diff_text, text=True,
                       stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if p.returncode != 0:
        raise RuntimeError("patch failed:\n" + p.stdout)

def call_openai(prompt: str) -> str:
    api_key = os.environ.get("OPENAI_API_KEY","").strip()
    if not api_key:
        raise RuntimeError("OPENAI_API_KEY is missing")
    model = os.environ.get("OPENAI_MODEL","").strip() or "gpt-4.1-mini"
    url = "https://api.openai.com/v1/responses"
    headers = {"Authorization": f"Bearer {api_key}", "Content-Type": "application/json"}
    payload = {
        "model": model,
        "input": prompt,
        "temperature": 0.1
    }
    r = requests.post(url, headers=headers, json=payload, timeout=120)
    if r.status_code >= 300:
        raise RuntimeError(f"OpenAI API error {r.status_code}: {r.text[:1000]}")
    data = r.json()
    # Extract text
    out = []
    for item in data.get("output", []):
        for c in item.get("content", []):
            if c.get("type") == "output_text":
                out.append(c.get("text",""))
    return "\n".join(out).strip()

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--plugin-dir", required=True)
    ap.add_argument("--reports-dir", required=True)
    ap.add_argument("--max-it", required=True)
    ap.add_argument("--wp-version", default="latest")
    ap.add_argument("--main-file", required=True)
    args = ap.parse_args()

    plug = os.path.abspath(args.plugin_dir)
    rep = os.path.abspath(args.reports_dir)
    max_it = int(args.max_it)

    for i in range(1, max_it+1):
        # Ensure WP integration scaffold and run integration tests each iteration
        run(["bash","-lc", f"python3 {os.path.abspath('tools/ensure_wp_integration_scaffold.py')} {plug} --main-file '{args.main_file}'"], os.getcwd())
        run(["bash","-lc", f"python3 {os.path.abspath('tools/validate_wp_integration_scaffold.py')} {plug} {rep} --main-file '{args.main_file}'"], os.getcwd())
        run(["bash","-lc", f"python3 {os.path.abspath('tools/run_wp_integration.py')} {plug} {rep} --wp-version '{args.wp_version}'"], os.getcwd())

        # Run gate
        rc, out = run(["bash","-lc", f"python3 {os.path.abspath('tools/run_gate.py')} {plug} {rep} --wp-version '{args.wp_version}' --main-file '{args.main_file}'"], os.getcwd())
        write(os.path.join(rep, f"gate-iter-{i}.txt"), out)

        gate_json = read(os.path.join(rep, "gate.json"))
        try:
            g = json.loads(gate_json) if gate_json else {}
        except Exception:
            g = {}
        passed = bool(g.get("summary", {}).get("pass"))
        if passed:
            print(f"Gate passing at iteration {i}.")
            return

        # Evidence for patching
        lint = read_clip(os.path.join(rep, "php-lint.txt"))
        phpcs = read_clip(os.path.join(rep, "phpcs.txt"))
        phpstan = read_clip(os.path.join(rep, "phpstan.txt"))
        phpunit = read_clip(os.path.join(rep, "phpunit.txt"))
        wp_integration = read_clip(os.path.join(rep, "wp-integration.json"))
        wp_integration_run = read_clip(os.path.join(rep, "wp-integration-run.json"))
        wp_tests_install = read_clip(os.path.join(rep, "wp-tests-install.txt"))
        gate = read_clip(os.path.join(rep, "gate.json"))

        prompt = f"""You are a senior WordPress plugin engineer.

Task:
- Produce ONE unified diff patch (git apply compatible) that fixes the reported problems.
Rules:
- Output ONLY the unified diff. No commentary.
- Prefer minimal, safe changes.
- Do not add runtime deps; dev-only changes are OK.
- Preserve backwards compatibility where reasonable.
- Fix security issues first.
- Fix WP integration scaffold/tests if failing.

Evidence:
[wp-integration scaffold]
{wp_integration}

[wp-integration run]
{wp_integration_run}

[wp-tests install]
{wp_tests_install}

[php-lint]
{lint}

[phpcs]
{phpcs}

[phpstan]
{phpstan}

[phpunit]
{phpunit}

[gate.json]
{gate}
"""

        diff = call_openai(prompt)
        if not diff.strip().startswith("diff --git"):
            raise RuntimeError("Model did not return a unified diff")
        apply_unified_diff(plug, diff)
        write(os.path.join(rep, f"autofix-diff-{i}.patch"), diff)

    print("Max iterations reached; gate still failing.")
    raise SystemExit(2)

if __name__ == "__main__":
    main()
