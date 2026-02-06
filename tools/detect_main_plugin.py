import os, re, json, sys

plugin_dir = sys.argv[1]
header_re = re.compile(r"^\s*Plugin Name:\s*(.+)$", re.IGNORECASE | re.MULTILINE)

best = None
for dirpath, _, filenames in os.walk(plugin_dir):
    for fn in filenames:
        if not fn.lower().endswith(".php"):
            continue
        p = os.path.join(dirpath, fn)
        try:
            s = open(p, "r", encoding="utf-8", errors="ignore").read(8192)
        except Exception:
            continue
        if header_re.search(s):
            # prefer root-level php
            score = 0
            rel = os.path.relpath(p, plugin_dir)
            if os.path.dirname(rel) == ".":
                score += 10
            score += max(0, 5 - rel.count(os.sep))
            if best is None or score > best[0]:
                best = (score, rel)

out = {"main_file": best[1] if best else ""}
print(json.dumps(out))
