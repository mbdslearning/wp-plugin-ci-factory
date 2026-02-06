import os, shutil, argparse

TEMPLATES = {
    "phpcs.xml.dist": "templates/phpcs.xml.dist",
    "phpstan.neon.dist": "templates/phpstan.neon.dist",
}

def ensure_file(dst_path: str, template_path: str) -> bool:
    if os.path.exists(dst_path):
        return False
    os.makedirs(os.path.dirname(dst_path), exist_ok=True)
    shutil.copyfile(template_path, dst_path)
    return True

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("plugin_dir")
    args = ap.parse_args()

    root = os.path.abspath(args.plugin_dir)
    changed = False

    for fname, tpath in TEMPLATES.items():
        dst = os.path.join(root, fname)
        if ensure_file(dst, os.path.join(os.getcwd(), tpath)):
            changed = True

    print("changed" if changed else "no_change")

if __name__ == "__main__":
    main()
