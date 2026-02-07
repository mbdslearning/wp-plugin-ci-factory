import os, sys, json, re

PLUGIN_HEADER_RE = re.compile(r'^\s*Plugin\s+Name\s*:\s*(.+)$', re.IGNORECASE | re.MULTILINE)

def find_main(plugin_dir: str) -> str:
    candidates = []
    for root, _, files in os.walk(plugin_dir):
        for fn in files:
            if not fn.lower().endswith('.php'):
                continue
            path = os.path.join(root, fn)
            try:
                data = open(path, 'r', encoding='utf-8', errors='ignore').read(4096)
            except Exception:
                continue
            if PLUGIN_HEADER_RE.search(data):
                rel = os.path.relpath(path, plugin_dir).replace('\\','/')
                candidates.append(rel)
    # Prefer top-level
    candidates.sort(key=lambda p: (p.count('/'), len(p)))
    return candidates[0] if candidates else ""

if __name__ == "__main__":
    plugin_dir = sys.argv[1]
    main_file = find_main(plugin_dir)
    out = {"main_file": main_file}
    sys.stdout.write(json.dumps(out, indent=2))
