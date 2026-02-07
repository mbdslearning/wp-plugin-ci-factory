import os, sys, zipfile

def zipdir(src, dest_zip):
    with zipfile.ZipFile(dest_zip, "w", compression=zipfile.ZIP_DEFLATED) as z:
        for root, _, files in os.walk(src):
            for fn in files:
                path = os.path.join(root, fn)
                rel = os.path.relpath(path, src)
                if rel.startswith("vendor/") or rel.startswith(".git/"):
                    # keep vendor in final? Usually NO for WP plugins. Skip.
                    continue
                z.write(path, rel)

if __name__ == "__main__":
    plugin_dir = sys.argv[1]
    out_zip = sys.argv[2]
    os.makedirs(os.path.dirname(out_zip), exist_ok=True)
    zipdir(plugin_dir, out_zip)
    print(out_zip)
