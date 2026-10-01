#!/usr/bin/env python3
"""Exercise the CI helper preparation contract without installing host binaries."""

import importlib.util
import subprocess
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location("native_helper_setup", Path(__file__).with_name("native-helper-setup.py"))
setup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(setup)


class NativeHelperSetupTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="native-helper-setup-test-")
        self.root = Path(self.temp.name) / "source"
        (self.root / "tools").mkdir(parents=True)
        (self.root / "src").mkdir()
        self.output = Path(self.temp.name) / "output"
        self.manifest = self.root / "tools/native-helpers.list"
        self.source = self.root / "src/helper.c"
        self.source.write_text('#include <stdio.h>\nint main(void) { printf("%d\\n", CONTRACT_VALUE); return 0; }\n')
        self.header = "version\t1\nflags\t-std=c11 -O2 -Wall -Wextra -Werror -DCONTRACT_VALUE=42\n"
        self.row = "rutorrent-custom-helper\tsrc/helper.c\n"
        self.manifest.write_text(self.header + self.row)

    def tearDown(self):
        self.temp.cleanup()

    def test_compiles_all_declared_names_and_actual_flags(self):
        self.manifest.write_text(self.header + self.row + "rutorrent-future-helper\tsrc/helper.c\n")
        names = setup.prepare(self.root, self.output)
        self.assertEqual(["rutorrent-custom-helper", "rutorrent-future-helper"], names)
        self.assertEqual(names, (self.output / "helpers.list").read_text().splitlines())
        for name in names:
            result = subprocess.run([str(self.output / name)], check=True, capture_output=True, text=True)
            self.assertEqual("42\n", result.stdout)

    def test_rejects_invalid_metadata_before_output_or_compiler(self):
        cases = [
            self.header.replace("version\t1", "version\t2") + self.row,
            "version\t1\n" + self.header + self.row,
            self.header + self.row + self.row,
            self.header + "unknown\tsrc/helper.c\n",
            self.header + "rutorrent-bad\tsrc/helper.c\textra\n",
            self.header.replace(" -DCONTRACT_VALUE=42", " -DVALUE=$(touch-marker)") + self.row,
            self.header.replace(" -DCONTRACT_VALUE=42", " -DVALUE=`touch-marker`") + self.row,
            self.header.replace(" -DCONTRACT_VALUE=42", " source.c") + self.row,
            self.row,
        ]
        for value in cases:
            with self.subTest(manifest=value):
                self.manifest.write_text(value)
                with self.assertRaises(ValueError):
                    setup.prepare(self.root, self.output, "must-not-invoke-a-compiler")
                self.assertFalse(self.output.exists())

    def test_rejects_missing_absolute_parent_and_symlink_escape_sources(self):
        external = Path(self.temp.name) / "external.c"
        external.write_text("int main(void) { return 0; }\n")
        (self.root / "src/escape.c").symlink_to(external)
        for source in ("src/missing.c", str(external), "../external.c", "src/escape.c"):
            with self.subTest(source=source):
                self.manifest.write_text(self.header + "rutorrent-test\t" + source + "\n")
                with self.assertRaises((ValueError, OSError)):
                    setup.prepare(self.root, self.output, "must-not-invoke-a-compiler")
                self.assertFalse(self.output.exists())

    def test_compiler_failure_does_not_publish_a_partial_contract(self):
        (self.root / "src/broken.c").write_text("this is not C\n")
        self.manifest.write_text(self.header + self.row + "rutorrent-broken\tsrc/broken.c\n")
        with self.assertRaises(subprocess.CalledProcessError):
            setup.prepare(self.root, self.output)
        self.assertEqual([], list(self.output.iterdir()))
        self.assertEqual([], list(self.output.parent.glob(".native-helper-stage-*")))

    def test_stale_output_cannot_replace_a_current_build(self):
        self.output.mkdir()
        stale = self.output / "rutorrent-custom-helper"
        stale.write_text("stale\n")
        stale.chmod(0o755)
        with self.assertRaises(ValueError):
            setup.prepare(self.root, self.output, "must-not-invoke-a-compiler")
        self.assertEqual("stale\n", stale.read_text())
        self.assertFalse((self.output / "helpers.list").exists())


if __name__ == "__main__":
    unittest.main()
