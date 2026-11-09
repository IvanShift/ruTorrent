# Tests

## Test javascript
```shell
npm install
npm test
```

## Test php
```shell
./php-test.sh
```

The PHP runner executes each `*Test.php` in its original source file. `TestCase`
classes run `setUp()` and `tearDown()` around each method, and `setUpClass()` and
`tearDownClass()` once per class; thrown `Throwable`s and failed assertions fail
the file, while later methods and classes still run. An interrupted or empty
TestCase run fails even if a child prints a passing summary. Standalone suites
must print at least one `ok - ` case and a positive `N tests, 0 failures` summary
and exit successfully. Child fatal/parse diagnostics also fail the containing file.

The runner clears the owner's bits of the umask it is started with
(`umask u=rwx`), so fixture directories made with a plain `mkdir()` stay usable
under a umask such as 0177. The group and other bits are kept.

An unfinished TestCase reports its refusal through STDERR before later shutdown
cleanup runs. If that diagnostic cannot be written, the process exits immediately.

Run the runner's isolated regression checks with:

```shell
bash tools/php-harness-test.sh
```

