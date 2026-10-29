#!/usr/bin/env python3
"""Native matrix bootstrap contracts, without installing helpers on the host."""
import importlib.util
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("matrix_native_leg", Path(__file__).with_name("matrix-native-leg.py"))
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class MatrixNativeLegTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="matrix-native-test-")
        self.root = Path(self.temporary.name)

    def tearDown(self):
        self.temporary.cleanup()

    def test_local_namespace_preserves_uid_proc_and_readonly_host(self):
        mounts = [(Path("/"), Path("/host"), True),
                  (self.root, Path("/host/private"), False)]
        with patch.dict(os.environ, {"PHP_INI_SCAN_DIR": "/php/config", "PHPRC": "/php/ini"}):
            args = runner.docker_run("my-leg", "php:8.1-cli", 1000, 100, mounts,
                                     ["/usr/bin/env", "php", "-v"], owner="token",
                                     local=True, groups=[100, 1000])
        for argument in ("--rm", "--pull=never", "--pid=host", "/:/host:ro",
                         "--userspec=1000:100", "--groups=100,1000",
                         "PHP_INI_SCAN_DIR=/php/config", "PHPRC=/php/ini"):
            self.assertIn(argument, args)
        self.assertEqual("none", args[args.index("--network") + 1])
        self.assertEqual("0:0", args[args.index("--user") + 1])
        self.assertNotIn("--privileged", args)
        self.assertEqual("/host/dev/shm:rw,nosuid,nodev,mode=1777,size=64m",
                         args[args.index("--tmpfs") + 1])
        container = runner.docker_run("image-leg", "php:7.4-cli", 1000, 100,
                                     mounts, ["php", "-v"], owner="token")
        self.assertNotIn("--tmpfs", container)

    def test_image_cc_adapter_compiles_manifest_flags_and_paths_with_spaces(self):
        tree = self.root / "source tree"
        (tree / "tools").mkdir(parents=True)
        (tree / "src").mkdir()
        (tree / "tools/native-helpers.list").write_text(
            "version\t1\nflags\t-std=c11 -O2 -Wall -Wextra -Werror -DVALUE=42\n"
            "rutorrent-fixture\tsrc/helper.c\n")
        (tree / "src/helper.c").write_text(
            '#include <stdio.h>\nint main(void) { printf("%d\\n", VALUE); return 0; }\n')
        native = self.root / "native outputs"
        native.mkdir()
        fake_bin = self.root / "fake-bin"
        fake_bin.mkdir()
        docker = fake_bin / "docker"
        # The real adapter supplies Docker argv; substitute only the container
        # execution while retaining real cc and the manifest flags/source paths.
        docker.write_text("#!/usr/bin/env python3\n" + '''import os, sys
args=sys.argv[1:]
assert args[:4] == ['run', '--rm', '--pull=never', '--network']
assert args[4] == 'none'
assert args[args.index('--entrypoint')+1] == 'cc'
assert args[args.index('--user')+1] == '1000:100'
assert 'io.rutorrent.matrix.owner=token' in args
index=args.index('php:7.4-cli')
os.execvp('cc', ['cc', *args[index+1:]])
''')
        docker.chmod(0o755)
        with patch.dict(os.environ, {"PATH": str(fake_bin) + ":" + os.environ["PATH"]}):
            output, names = runner.compile_helpers(tree, native, "php:7.4-cli", "leg", 1000, 100, "token")
        self.assertEqual(["rutorrent-fixture"], names)
        self.assertEqual("42\n", subprocess.check_output([output / names[0]], text=True))
        self.assertEqual(0o755, (output / names[0]).stat().st_mode & 0o777)
        self.assertEqual("rutorrent-fixture\n", (output / "helpers.list").read_text())

    def test_container_name_collision_never_removes_the_existing_occupant(self):
        identity = subprocess.CompletedProcess([], 0, "user-existing-id another-owner\n", "")
        with patch.object(runner.subprocess, "run", return_value=identity) as execute:
            runner.remove_owned_container("colliding-name", "our-token")
        self.assertEqual(1, execute.call_count)
        self.assertEqual("inspect", execute.call_args.args[0][2])

    def test_cleanup_uses_owned_id_and_removes_anonymous_volumes(self):
        identity = subprocess.CompletedProcess([], 0, "owned-container-id our-token\n", "")
        with patch.object(runner.subprocess, "run", return_value=identity) as execute:
            runner.remove_owned_container("reusable-name", "our-token")
        self.assertEqual(["docker", "rm", "-f", "-v", "owned-container-id"],
                         execute.call_args.args[0])

    def test_focused_container_checks_source_and_keeps_logs_private(self):
        tree = self.root / "tree"
        (tree / "tests/php").mkdir(parents=True)
        (tree / "tests/php/FixtureTest.php").write_text("<?php\n")
        self.declare_rename_helper(tree, "rutorrent-fixture-rename")
        args = runner.test_command(Path("/w"), Path("/mtmp"),
                                   ["tests/php/FixtureTest.php"], tree)
        self.assertIn("RU_LOG_FILE=/mtmp/application-errors.log", args)
        self.assertIn("auto_append_file=/w/tests/php/TestCaseRunner.php", args[-1])
        with self.assertRaises(ValueError):
            runner.test_command(Path("/w"), Path("/mtmp"), ["tests/php/MissingTest.php"], tree)

    def declare_rename_helper(self, tree, name):
        (tree / "tools").mkdir(parents=True)
        (tree / "plugins/erasedata").mkdir(parents=True)
        (tree / "plugins/erasedata/rename-noreplace.c").write_text("int main(void) { return 0; }\n")
        (tree / "tools/native-helpers.list").write_text(
            "version\t1\nflags\t-O2\n" + name + "\tplugins/erasedata/rename-noreplace.c\n")

    def test_probe_follows_manifest_when_rename_helper_basename_changes(self):
        tree = self.root / "renamed-helper"
        self.declare_rename_helper(tree, "rutorrent-renamed-rename-probe")
        command = runner.test_command(Path("/w"), Path("/mtmp"), (), tree)[-1]
        self.assertIn("/usr/local/bin/rutorrent-renamed-rename-probe", command)
        self.assertNotIn("/usr/local/bin/rutorrent-erasedata-rename-noreplace", command)

    def test_compile_failure_stops_before_php_and_cleans_owned_scratch(self):
        directory = self.root / "leg"
        (directory / "tree").mkdir(parents=True)
        (directory / "tmp").mkdir()
        with patch.object(runner, "compile_helpers", side_effect=RuntimeError("compile failed")), \
             patch.object(runner, "docker_run") as launch, \
             patch.object(runner, "remove_owned_container") as cleanup:
            with self.assertRaisesRegex(RuntimeError, "compile failed"):
                runner.run_leg(directory, "7.4", "own-leg", os.getuid(), os.getgid(), owner="our-token")
        launch.assert_not_called()
        cleanup.assert_called_once_with("own-leg", "our-token")
        self.assertFalse((directory / "native").exists())

    def test_standalone_creates_private_tmp_before_compilation(self):
        directory = self.root / "fresh"
        (directory / "tree").mkdir(parents=True)
        def inspect_tmp(*args):
            self.assertTrue((directory / "tmp").is_dir())
            self.assertEqual(0o700, (directory / "tmp").stat().st_mode & 0o777)
            self.assertEqual(os.getuid(), (directory / "tmp").stat().st_uid)
            raise RuntimeError("build-cut")
        with patch.object(runner, "compile_helpers", side_effect=inspect_tmp), \
             patch.object(runner, "remove_owned_container"):
            with self.assertRaisesRegex(RuntimeError, "build-cut"):
                runner.run_leg(directory, "7.4", "leg", os.getuid(), os.getgid())

    def test_local_resolved_tmp_over_budget_is_refused_before_build(self):
        directory = self.root / ("long" * 24)
        (directory / "tree").mkdir(parents=True)
        alias = self.root / "alias"
        alias.symlink_to(directory, target_is_directory=True)
        with patch.object(runner, "compile_helpers", side_effect=RuntimeError("build-reached")), \
             patch.object(runner, "remove_owned_container"):
            with self.assertRaisesRegex(RuntimeError, "58"):
                runner.run_leg(alias, "local", "leg", os.getuid(), os.getgid())

    def test_parallel_local_tmp_keeps_room_for_the_per_file_directory(self):
        with tempfile.TemporaryDirectory(dir="/tmp") as short:
            # 56 bytes fits the sequential 58 but not the parallel 54.
            directory = Path(short) / ("p" * (56 - len(short) - len("//tmp")))
            (directory / "tree").mkdir(parents=True)
            self.assertEqual(56, len(os.fsencode(directory / "tmp")))
            with patch.object(runner, "compile_helpers", side_effect=RuntimeError("build-reached")), \
                 patch.object(runner, "remove_owned_container"):
                with self.assertRaisesRegex(RuntimeError, "at most 54 bytes"):
                    runner.run_leg(directory, "local", "leg", os.getuid(), os.getgid(), jobs=2)
                with self.assertRaisesRegex(RuntimeError, "build-reached"):
                    runner.run_leg(directory, "local", "leg", os.getuid(), os.getgid(), jobs=1)

    def test_full_suite_gets_jobs_and_a_durations_file_in_its_tmpdir(self):
        tree = self.root / "jobs"
        self.declare_rename_helper(tree, "rutorrent-fixture-rename")
        command = runner.test_command(Path("/w"), Path("/mtmp"), (), tree, 6)[-1]
        self.assertIn("env PHP_TEST_JOBS=6 PHP_TEST_TIMINGS=/mtmp/php-test-timings bash tests/php-test.sh",
                      command)

    def test_durations_round_trip_through_the_leg_tmpdir(self):
        directory = self.root / "durations"
        self.declare_rename_helper(directory / "tree", "rutorrent-fixture-rename")
        kept = self.root / "php-timings-7.4"
        kept.write_bytes(b"1\tphp/OldTest.php\0")
        def run_suite(*args, **kwargs):
            produced = directory / "tmp" / runner.TIMINGS_NAME
            self.assertEqual(b"1\tphp/OldTest.php\0", produced.read_bytes())
            produced.write_bytes(b"2\tphp/NewTest.php\0")
            raise subprocess.CalledProcessError(1, "php-test.sh")
        with patch.object(runner, "compile_helpers", return_value=(self.root, [])), \
             patch.object(runner.subprocess, "run", side_effect=run_suite), \
             patch.object(runner, "remove_owned_container"):
            with self.assertRaises(subprocess.CalledProcessError):
                runner.run_leg(directory, "7.4", "leg", os.getuid(), os.getgid(), jobs=6, timings=kept)
        # A failed suite still leaves its durations for the next start order.
        self.assertEqual(b"2\tphp/NewTest.php\0", kept.read_bytes())
        self.assertEqual(["php-timings-7.4"], sorted(p.name for p in self.root.glob("php-timings-*")))


if __name__ == "__main__":
    unittest.main()
