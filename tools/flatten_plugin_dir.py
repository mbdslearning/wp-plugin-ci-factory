import os, sys, shutil

def flatten(root: str) -> None:
    # If root contains a single top folder and no obvious plugin files, move contents up.
    entries = [e for e in os.listdir(root) if not e.startswith('__MACOSX')]
    if len(entries) != 1:
        return
    top = os.path.join(root, entries[0])
    if not os.path.isdir(top):
        return
    # Heuristic: if top contains php files / readme, flatten.
    inner = os.listdir(top)
    has_php = any(x.lower().endswith('.php') for x in inner)
    has_readme = any(x.lower().startswith('readme') for x in inner)
    if not (has_php or has_readme):
        return
    tmp = os.path.join(root, "__tmp_flatten__")
    os.makedirs(tmp, exist_ok=True)
    for name in inner:
        shutil.move(os.path.join(top, name), os.path.join(tmp, name))
    shutil.rmtree(top)
    for name in os.listdir(tmp):
        shutil.move(os.path.join(tmp, name), os.path.join(root, name))
    shutil.rmtree(tmp)

if __name__ == "__main__":
    flatten(sys.argv[1])
