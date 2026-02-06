import os, sys, shutil

root = sys.argv[1]
entries = [e for e in os.listdir(root) if not e.startswith("__MACOSX")]
if len(entries) == 1:
    candidate = os.path.join(root, entries[0])
    if os.path.isdir(candidate):
        tmp = os.path.join(root, "__tmp_flatten__")
        os.makedirs(tmp, exist_ok=True)
        for name in os.listdir(candidate):
            shutil.move(os.path.join(candidate, name), os.path.join(tmp, name))
        shutil.rmtree(candidate)
        for name in os.listdir(tmp):
            shutil.move(os.path.join(tmp, name), os.path.join(root, name))
        shutil.rmtree(tmp)
