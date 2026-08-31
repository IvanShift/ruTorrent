# PHP CI matrix observability — verification

Date: 2026-08-31.

## Incident

GitHub Actions run `33386106599` checked out
`fe5313fa8fb47e0a12dfdd23087dbf6209950309`. The PHP 8.1 job
`99468969201` exited with status `1`; the PHP 7.4 job was then cancelled by
matrix fail-fast. Jest passed.

The public job page and API expose only the final exit status. Downloading the
job log without repository-owner authorization returns `403`, and the run has
no artifacts. The failing test filename therefore cannot be recovered from
public evidence.

`fe5313fa` changes only `js/rtorrent.js`. Clean PHP 8.1 reproductions of the
same full suite passed, so no PHP/application root cause was demonstrated and
no retry, skipped test, stub, or false-green handling was added.

## Implemented diagnostic improvement

Local master commit `95f9ab6f` sets `strategy.fail-fast: false` only for the
existing PHP matrix in `.github/workflows/tests.yml`.

The semantics are intentionally narrow:

- PHP 7.4 and PHP 8.1 both finish even if one fails;
- a failed matrix job still makes the workflow fail;
- the test commands, supported versions, Jest job, and application code remain
  unchanged.

The source branch commit was `7224b767`; independent review found no Critical,
Important, or Minor issue and approved the patch for integration. Exact scope
is one workflow file. YAML semantics, `git diff --check`, and a clean writable
PHP 8.1 full-suite run passed.

## Remaining evidence gate

The next repeated failure must be diagnosed from the completed PHP 7.4 and 8.1
job logs, using the harness's per-file `> php ...` output. An application or
test fix requires that concrete failing filename and a reproducible RED; this
observability commit is not represented as a fix for the unknown original
failure.

No push or deployment was performed.
