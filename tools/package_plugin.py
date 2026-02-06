import os, sys, zipfile, pathlib

plugin_dir = sys.argv[1]
zip_path = sys.argv[2]

plugin_dir = os.path.abspath(plugin_dir)
os.makedirs(os.path.dirname(zip_path), exist_ok=True)

with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as z:
    for p in pathlib.Path(plugin_dir).rglob("*"):
        if p.is_dir():
            continue
        rel = p.relative_to(plugin_dir)
        if str(rel).startswith("vendor/"):
            # Include vendor only if you truly ship it; most plugins should NOT.
            continue
        if str(rel).startswith(".git/") or str(rel).endswith(".ci_autofix.patch"):
            continue
        z.write(str(p), arcname=str(rel))
print(zip_path)
