# Title

Test rTorrent 0.16.21 compatibility

# Body

Add explicit rTorrent 0.16.21 coverage to the existing compatibility tests.

- verify that 0.16.21 inherits the canonical port-command aliases;
- verify that `d.is_partially_done` remains a direct command;
- include 0.16.21 in the environment support-policy test.

No runtime behavior changes are included. The production compatibility logic
already supports this release; these cases make a future regression visible in
CI instead of leaving 0.16.21 covered only implicitly.

Tests:

- `npm test -- --runInBand js/rtorrent.spec.js`;
- focused `RequirementsTest` and `RtorrentCompatibilityTest` on PHP 7.4, 8.1,
  and 8.5;
- JavaScript/PHP syntax checks and `git diff --check`.

# Handoff

- upstream base: `f19c9d86`;
- local branch: `up/rtorrent-0-16-21-f19`;
- commit: `cbacef8e6e7bae1eec7daf1fcbe1f1644c334273`;
- exact diff: three test files, `+9/-4`, no runtime or fork-only files;
- independent review: no findings, `Ready to merge: Yes`.

Publish without modifying the historical donor branch:

```sh
git push -u origin up/rtorrent-0-16-21-f19
```
