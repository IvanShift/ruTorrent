#!/usr/bin/env python3
"""Prepare manifest-declared test helpers; installation is an explicit CI step."""

import argparse
import os
import re
import shutil
import subprocess
import tempfile
from pathlib import Path


def read_manifest(source_root):
    source_root = Path(source_root).resolve(strict=True)
    version = None
    flags = None
    helpers = {}
    for line in (source_root / "tools/native-helpers.list").read_text().splitlines():
        if not line or line.startswith("#"):
            continue
        fields = line.split("\t")
        if len(fields) != 2 or not fields[1]:
            raise ValueError("invalid native helper manifest columns")
        name, value = fields
        if name == "version":
            if version is not None or value != "1":
                raise ValueError("unsupported or repeated native helper ABI")
            version = value
        elif name == "flags":
            if flags is not None:
                raise ValueError("repeated native helper flags")
            flags = value.split(" ")
            if not all(re.fullmatch(r"-[A-Za-z0-9_=.+-]+", flag) for flag in flags):
                raise ValueError("invalid native helper compiler flag")
        else:
            if not re.fullmatch(r"rutorrent-[a-z-]+", name) or name in helpers:
                raise ValueError("invalid or repeated native helper basename")
            relative = Path(value)
            if (not re.fullmatch(r"[A-Za-z0-9_./-]+\.c", value)
                    or relative.is_absolute() or ".." in relative.parts):
                raise ValueError("invalid native helper source path")
            source = (source_root / relative).resolve(strict=True)
            if not source.is_file() or source_root not in source.parents:
                raise ValueError("native helper source escapes source root")
            helpers[name] = source
    if version != "1" or flags is None or not helpers:
        raise ValueError("incomplete native helper manifest")
    return flags, helpers


def prepare(source_root, output_dir, compiler="cc"):
    flags, helpers = read_manifest(source_root)
    output = Path(output_dir)
    if output.is_symlink():
        raise ValueError("helper output must be a physical directory")
    output.mkdir(mode=0o700, parents=True, exist_ok=True)
    if any(output.iterdir()):
        raise ValueError("helper output must be empty; stale binaries are not candidates")
    # Publish the list only after every declared helper has compiled successfully.
    # Compiler arguments are an array; manifest words are never shell-evaluated.
    with tempfile.TemporaryDirectory(prefix=".native-helper-stage-", dir=output.parent) as stage:
        stage = Path(stage)
        for name, source in helpers.items():
            binary = stage / name
            subprocess.run([compiler, *flags, "-o", str(binary), str(source)], check=True)
            if not binary.is_file() or not os.access(binary, os.X_OK):
                raise ValueError("compiler did not produce an executable helper: " + name)
        for name in helpers:
            shutil.move(str(stage / name), str(output / name))
        (output / "helpers.list").write_text("".join(name + "\n" for name in helpers))
    return list(helpers)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-root", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--cc", default="cc", help="One compiler executable, not a shell command")
    args = parser.parse_args()
    try:
        names = prepare(args.source_root, args.output_dir, args.cc)
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        parser.exit(1, "native-helper-setup: " + str(error) + "\n")
    print("Prepared native helpers: " + ", ".join(names))


if __name__ == "__main__":
    main()
