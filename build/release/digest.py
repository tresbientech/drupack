"""Records and checks what an artifact holds, so a job reads the files the job before it uploaded.

Usage: digest.py record PATH
       digest.py check [--exactly] RECORDS [NAME=]FOLDER...

record prints the digest of PATH: the sha256 of the sorted "FILEDIGEST  PATH" lines of its files.
A file counts as a folder holding itself, as upload-artifact sends it. check reads RECORDS, a JSON
object of artifact name to digest, and stops unless each FOLDER matches the record of its NAME, its
own name by default. --exactly also stops when a record has no FOLDER.
"""

import argparse
import hashlib
import json
import os
import sys
from pathlib import Path


def digest(path):
    path = Path(path)
    files = [path] if path.is_file() else [
        Path(folder, name) for folder, folders, names in os.walk(path) for name in folders + names
        if name in names or Path(folder, name).is_symlink()]
    lines = []
    for file in files:
        name = file.name if file == path else file.relative_to(path).as_posix()
        # upload-artifact follows a link and leaves a dot-file out, so neither has one record.
        if file.is_symlink() or any(part.startswith(".") for part in name.split("/")):
            raise SystemExit(f"{file}: a link or a dot-file has no record")
        lines.append(f"{hashlib.sha256(file.read_bytes()).hexdigest()}  {name}\n")
    # A path with no file would give every empty folder the same record.
    if not lines:
        raise SystemExit(f"{path}: no file to record")
    return hashlib.sha256("".join(sorted(lines)).encode()).hexdigest()


def check(records, folders, exactly):
    named = dict(folder.split("=", 1) if "=" in folder else (Path(folder).name, folder) for folder in folders)
    errors = [f"{name}: no record" if name not in records else f"{name}: its files differ from the record"
              for name, folder in named.items() if records.get(name) != digest(folder)]
    if exactly:
        errors += [f"{name}: no artifact" for name in records if name not in named]
    return errors


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("record").add_argument("path")
    checked = commands.add_parser("check")
    checked.add_argument("--exactly", action="store_true")
    checked.add_argument("records", type=json.loads)
    checked.add_argument("folders", nargs="+")
    args = parser.parse_args(argv)
    if args.command == "record":
        print(digest(args.path))
        return
    errors = check(args.records, args.folders, args.exactly)
    if errors:
        raise SystemExit("\n".join(errors))


if __name__ == "__main__":
    main(sys.argv[1:])
