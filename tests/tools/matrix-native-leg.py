#!/usr/bin/env python3
"""Run one matrix leg with freshly built helpers at their shipped fixed paths."""

import argparse
import importlib.util
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import sys
import uuid

# Exports and helper scratch are disposable; do not add Python caches to either.
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location(
    "native_helper_setup", Path(__file__).with_name("native-helper-setup.py"))
setup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(setup)
LOCAL_LAUNCHER = "php:8.1-cli"
# A parallel php-test.sh keeps its per-file durations here, in the leg's
# TMPDIR; run_leg carries them to and from the file named by --timings.
TIMINGS_NAME = "php-test-timings"
# The 49-byte socket suffix of SCGITransportFixture leaves TMPDIR 58 of the
# 107 bytes of sun_path; a parallel php-test.sh adds a 4-byte subdirectory.
SOCKET_TMPDIR_BUDGET = 58
PARALLEL_TMPDIR_SUFFIX = 4


def docker_run(name, image, uid, gid, mounts, command, *, owner, local=False, groups=()):
    args = ["docker", "run", "--rm", "--pull=never", "--network", "none",
            "--name", name, "--label", "io.rutorrent.matrix.owner=" + owner, "--user", "0:0" if local else str(uid) + ":" + str(gid)]
    if local:
        # The chroot sees the host's /proc. Keep getpid() and /proc/<pid>
        # in that same namespace, with original numeric UIDs and no UID remap.
        # EXDEV fixtures need writable shared memory on a second filesystem.
        # Mount a bounded private tmpfs rather than exposing the host's /dev/shm.
        args += ["--pid=host", "--entrypoint", "/usr/sbin/chroot",
                 "--tmpfs", "/host/dev/shm:rw,nosuid,nodev,mode=1777,size=64m"]
    else:
        args += ["--entrypoint", command[0]]
    for source, destination, readonly in mounts:
        args += ["-v", str(source) + ":" + str(destination) + (":ro" if readonly else "")]
    if local:
        # Keep the host PHP extension/configuration selection, when explicitly set.
        for variable in ("PATH", "PHPRC", "PHP_INI_SCAN_DIR"):
            if variable in os.environ:
                args += ["-e", variable + "=" + os.environ[variable]]
        args += [image, "--userspec=" + str(uid) + ":" + str(gid),
                 "--groups=" + ",".join(map(str, groups)), "/host", *command]
    else:
        args += [image, *command[1:]]
    return args


def compile_helpers(tree, native, image, name, uid, gid, owner):
    output = native / "output"
    if image is None:
        compiler = shutil.which("cc")
        if compiler is None:
            raise RuntimeError("local native helpers require cc in PATH")
    else:
        # Reuse the CI's validated manifest/argv builder. Its compiler adapter
        # runs cc in the tested PHP image, avoiding cross-glibc host binaries.
        compiler = native / "container-cc"
        args = docker_run(name, image, uid, gid,
                          [(tree, tree, True), (native, native, False)], ["cc"], owner=owner)
        compiler.write_text("#!/bin/sh\nexec " + shlex.join(args) + ' "$@"\n')
        compiler.chmod(0o700)
    names = setup.prepare(tree, output, str(compiler))
    for helper in names:
        (output / helper).chmod(0o755)
    return output, names


def test_command(tree, temporary, test_files, source_tree=None, jobs=1):
    source_tree = tree if source_tree is None else source_tree
    _, helpers = setup.read_manifest(source_tree)
    rename_source = (source_tree / "plugins/erasedata/rename-noreplace.c").resolve(strict=True)
    rename_names = [name for name, source in helpers.items() if source == rename_source]
    if len(rename_names) != 1:
        raise ValueError("manifest must declare exactly one erasedata rename helper")
    probe = ["bash", "tests/plugins/erasedata/rename-noreplace-test.sh",
             "/usr/local/bin/" + rename_names[0]]
    commands = [shlex.join(probe)]
    if test_files:
        for test_file in test_files:
            relative = Path(test_file)
            if (relative.is_absolute() or ".." in relative.parts
                    or not test_file.startswith(("tests/php/", "tests/plugins/"))
                    or not test_file.endswith("Test.php") or not (source_tree / relative).is_file()):
                raise ValueError("invalid focused PHP test: " + test_file)
            commands += ["printf '%s\\n' " + shlex.quote("> php " + test_file),
                         shlex.join(["env", "RUTORRENT_PHP_TEST_RUNNER=1", "php",
                                     "-c", "tests/php-test.ini", "-d",
                                     "auto_append_file=" + str(tree / "tests/php/TestCaseRunner.php"),
                                     "-f", test_file])]
    else:
        commands += [shlex.join(["env", "PHP_TEST_JOBS=" + str(jobs),
                                 "PHP_TEST_TIMINGS=" + str(temporary / TIMINGS_NAME),
                                 "bash", "tests/php-test.sh"])]
    return ["/usr/bin/env", "HOME=" + str(temporary), "TMPDIR=" + str(temporary),
            "RU_LOG_FILE=" + str(temporary / "application-errors.log"),
            "bash", "-e", "-o", "pipefail", "-c",
            "cd " + shlex.quote(str(tree)) + " && " + " && ".join(commands)]


def remove_owned_container(name, owner):
    identity = subprocess.run(
        ["docker", "container", "inspect", "--format",
         '{{.Id}} {{index .Config.Labels "io.rutorrent.matrix.owner"}}', name],
        capture_output=True, text=True, check=False)
    fields = identity.stdout.strip().split()
    if identity.returncode == 0 and len(fields) == 2 and fields[1] == owner:
        subprocess.run(["docker", "rm", "-f", "-v", fields[0]],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)


def save_timings(produced, timings):
    """Atomically keep a leg's durations for the next run's start order."""
    if timings is None or not produced.is_file():
        return
    staged = timings.with_name(timings.name + "." + uuid.uuid4().hex)
    try:
        shutil.copyfile(produced, staged)
        os.replace(staged, timings)
    except OSError as error:
        staged.unlink(missing_ok=True)
        print("matrix native helpers: cannot keep PHP test durations: " + str(error), flush=True)


def run_leg(directory, leg, name, uid, gid, test_files=(), owner=None, jobs=1, timings=None):
    owner = uuid.uuid4().hex if owner is None else owner
    directory = Path(directory).resolve(strict=True)
    tree, temporary = directory / "tree", directory / "tmp"
    budget = SOCKET_TMPDIR_BUDGET - (PARALLEL_TMPDIR_SUFFIX if jobs > 1 else 0)
    if leg == "local" and len(os.fsencode(temporary)) > budget:
        raise RuntimeError("resolved local TMPDIR must be at most " + str(budget) + " bytes: "
                           + str(temporary))
    if temporary.is_symlink():
        raise RuntimeError("matrix TMPDIR must be a physical private directory")
    temporary.mkdir(mode=0o700, exist_ok=True)
    if temporary.stat().st_uid != uid:
        raise RuntimeError("matrix TMPDIR must be owned by the caller")
    temporary.chmod(0o700)
    if timings is not None and timings.is_file():
        shutil.copyfile(timings, temporary / TIMINGS_NAME)
    native = directory / "native"
    native.mkdir(mode=0o700)
    try:
        image = LOCAL_LAUNCHER if leg == "local" else "php:" + leg + "-cli"
        output, names = compile_helpers(tree, native, None if leg == "local" else image,
                                        name, uid, gid, owner)
        if leg == "local":
            # Overlay a private copy, never install, replace or chmod host files.
            # Existing operator commands/symlinks stay available in the chroot.
            local_bin = native / "local-bin"
            shutil.copytree("/usr/local/bin", local_bin, symlinks=True)
            local_bin.chmod(0o755)
            for helper in names:
                target = local_bin / helper
                if target.is_symlink() or target.exists():
                    target.unlink()
                shutil.copy2(output / helper, target)
            command = test_command(tree, temporary, test_files, jobs=jobs)
            mounts = [(Path("/"), Path("/host"), True),
                      (tree, Path("/host") / tree.relative_to("/"), False),
                      (temporary, Path("/host") / temporary.relative_to("/"), False),
                      (local_bin, Path("/host/usr/local/bin"), True)]
            args = docker_run(name, image, uid, gid, mounts, command, owner=owner, local=True,
                              groups=sorted(set([gid, *os.getgroups()])))
        else:
            command = test_command(Path("/w"), Path("/mtmp"), test_files, tree, jobs)
            mounts = [(tree, Path("/w"), False), (temporary, Path("/mtmp"), False)]
            mounts += [(output / helper, Path("/usr/local/bin") / helper, True)
                       for helper in names]
            args = docker_run(name, image, uid, gid, mounts, command, owner=owner)
        print("matrix native helpers: " + leg + ": " + ", ".join(names), flush=True)
        subprocess.run(args, check=True)
    finally:
        # Covers a late create or interrupted Docker client. Anonymous image
        # volumes belong to this named leg and must be removed together with it.
        remove_owned_container(name, owner)
        shutil.rmtree(native)
        save_timings(temporary / TIMINGS_NAME, timings)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--directory", type=Path, required=True)
    parser.add_argument("--leg", choices=("local", "7.4", "8.1"), required=True)
    parser.add_argument("--name", required=True)
    parser.add_argument("--owner", help="Private run ownership token used by interrupt cleanup")
    parser.add_argument("--uid", type=int, default=os.getuid())
    parser.add_argument("--gid", type=int, default=os.getgid())
    # This standalone tool can check a few files without modifying matrix.sh's
    # full exported-file accounting or ever recording its full-green marker.
    parser.add_argument("--test-file", action="append", default=[])
    parser.add_argument("--jobs", type=int, default=1, help="PHP test files run at once")
    parser.add_argument("--timings", type=Path,
                        help="File that keeps per-file durations between full runs")
    args = parser.parse_args()
    if args.jobs < 1:
        parser.error("--jobs must be at least 1")
    try:
        run_leg(args.directory, args.leg, args.name, args.uid, args.gid, args.test_file, args.owner,
                args.jobs, args.timings)
    except (OSError, RuntimeError, ValueError, subprocess.CalledProcessError) as error:
        parser.exit(1, "matrix native helpers: " + str(error) + "\n")


if __name__ == "__main__":
    main()
