# Upstream package handoff and local squash implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans
> for inline execution. No new subagents, push, PR, deploy or package 6 work.

**Goal:** Extract the completed upstream-owned packages, prove their relationship
to the 18-package registry, then replace only our unpublished master series with
one first-parent commit while preserving user work and upstream ancestry.

**Architecture:** Freeze master and retain a backup. Prepare clean package branches
against upstream or an explicitly named prerequisite, never by copying the fork.
Keep package delivery distinct from implementation closure. Finish with the same
product tree, a single aggregate commit after ebb60a7e, and upstream as second parent.

**Tech Stack:** Git, PHP 7.4/8.1/8.5, PHPStan, Jest, disposable rTorrent labs.

**Spec:** User-approved ordering in this conversation; repository AGENTS.md;
STATUS-18-PACKAGES-2026-09-03.md and PACKAGE-5-14-INTEGRATION-2026-09-05.md.

## Global constraints

- Freeze source master at 92d7111f573fa81e9032e708dcdd3fad9081a7c9.
- Preserve user commit ebb60a7eb272b82d6bbdc7b1cfaa5427be3c6cb0 separately.
- Current published fork is 72d1885ca02358bb1420d1a812450b61e77521f7.
- Current upstream is b4e84b641ebef7adeb91ec3830d3934bd1885bd1.
- Recheck remote refs before replacing master; never force-push.
- Preserve the four root log files and all existing external worktrees.
- Upstream branches exclude tasks/, AGENTS.md, .codex/, Docker notes and
  unrelated fork changes. The user explicitly authorized independent
  rutracker_check fixes; the full plugin must wait until fully complete.
- Do not treat a stacked branch as ready for a PR against upstream master
  before its prerequisite lands or an independent carve is verified.
- Code comments/test assertions remain English; PHP floor is 7.4.
- Full PHP harness runs from tests/ outside the restricted PID namespace.
- Only fresh tests of the selected branch count as its acceptance.

## Task 1: Inventory, freeze, baseline and package ownership

**Files:** This plan/report and the current 18-package status document.
**Interfaces:** Produce frozen refs, exact per-package paths and dependency map.

- [x] Read current AGENTS/skills and registry; fetch origin/upstream master.
- [x] Create backup/upstream-handoff-pre-squash-20260905 at the frozen master.
- [x] Create an isolated upstream worktree under ignored .worktrees/.
- [x] Run the clean upstream full PHP harness and Jest with existing dependencies.
- [x] Compare candidate package commits with current upstream, including code
  dependencies, old test helpers and copied endpoint assumptions.
- [x] Record 4/5/13/14 as extraction candidates; 15 as separate eligibility review.
  The user authorized independent plugin fixes, not wholesale plugin delivery.

## Task 2: Independent SCGI and alias branches

**Files — package 4:** README.md; conf/config.php (RPC settings only);
php/scgitransport.php; php/xmlrpc.php (include/send only); rpc2.php (transport
only); tests/php/SCGITransportFixture.php; tests/php/SCGITransportTest.php.
**Files — package 13:** php/settings.php (comment only);
tests/php/RtorrentCompatibilityTest.php; tests/php/SocketAllocLimitsTest.php
(comment only); tests/js/rtorrent.spec.js.
**Interfaces:** Package 4 exports rSCGITransport::send and RAW/BODY modes;
package 13 preserves the existing version map and pins actual caller surface.

- [x] Create up/scgi-transport-v2 and up/rtorrent-alias-surface from upstream.
- [x] Apply only the approved package deltas, retaining later focused test fixes.
  Do not carry xmlrpc_path.php, rawFaultString, erasedata or unrelated config.
- [x] For SCGI copied tests retain upstream's inline resolver; remove only the
  obsolete requirement to copy the fork-only resolver file.
- [x] Run focused suites on PHP 7.4/8.1/8.5, syntax and full branch PHP hooks;
  run alias Jest coverage and full PHPStan production scan.
- [x] Inspect exact diff/name sets, commit one logical patch on each branch,
  and prepare English PR bodies with before/after and verified evidence.

## Task 3: Recovery/proxy extraction and downstream correspondence

**Files — package 5:** plugins/retrackers/init.php, done.php, run.sh, update.php;
tests/plugins/retrackers/UpdateTest.php, RetrackersUpdateSequenceTest.php.
**Files — package 14 policy:** conf/xmlrpc_proxy.php, php/xmlrpc_proxy.php;
tests/php/XMLRPCProxyTest.php, XMLRPCProxyContractFixture.php,
XMLRPCProxyContractTest.php, XMLRPCProxyRejectionTest.php,
XMLRPCProxyEntrypointTest.php.
**Prerequisite adaptation boundary:** already-approved httprpc input/root policy
in plugins/httprpc/action.php and conf.php, if absent on current upstream. Keep
upstream's inline path resolver; do not import shared xmlrpc_path.php or the
removewithdata branch. Name any additional net paths in the final report.
**Interfaces:** Package 5 consumes package 4 RAW transport; 14 copied endpoint
tests must target real upstream doors, not assume fork bootstrap infrastructure.

- [x] Create up/retrackers-recovery-v2 from the verified package 4 tip.
- [x] Create up/xmlrpc-proxy-policy on the smallest verified prerequisite base;
  record whether transport is a product dependency or only a test dependency.
- [x] Apply the final reviewed package bytes. Adapt only baseline-specific
  imports/fixtures/door wiring without weakening the agreed policy.
- [x] Run real-entrypoint/version suites, mutation-sensitive boundary checks,
  full branch PHP hooks and static gates. For recovery, rerun real 0.9.8/0.16.21
  callback/rollback/lost-reply checks on the extracted tree.
- [x] Compare frozen sequence bodies and all relevant method-name sets; record
  intentional upstream-vs-fork differences and retained assertions.
- [x] Commit only independently verified deltas and label blocked candidates
  honestly if new authority or contract changes would be needed.
- [x] Map each PR to packages 1-18: closure, prerequisite, partial coverage or
  no relationship. Explicitly retain open 6-12/16-18 and pending external #6.
- [x] Prepare English bodies, Russian purpose, exact push commands and required
  publication order. Do not execute those commands.

## Task 4: Same-tree master squash and durable handoff

**Files:** Task reports/status/README/PLAN/CROSSWALK and the authorized AGENTS.md policy clarification; product tree is frozen.
**Interfaces:** One first-parent aggregate after ebb60a7e; second parent upstream.

- [x] Save branch SHAs, per-PR paths, verification logs and downstream crosswalk
  in task-local Markdown; update the authoritative package status links.
- [x] Prepare aggregate Git objects before moving master, with parents ebb60a7e and b4e84b64;
  do not flatten away the upstream relationship or rewrite published ancestry.
- [x] Prove its product tree equals the accepted 92d7111f tree, with only the
  intended new task documents outside that equality boundary.
- [x] Run the final full PHP/Jest gates and ordinary commit hook.
- [x] Recheck master still has the frozen source tip and origin is unchanged;
  replace local master using a guarded ref update, preserving the backup.
- [x] Verify one first-parent commit, upstream ancestry, unchanged user commit,
  clean tracked tree and unchanged logs. Leave prepared branches available;
  report exact refs and stop without push.

## Live execution notes

Fresh fetch confirmed the frozen refs above. Backup and five isolated package branches exist; the final
aggregate preserves the accepted product tree and the upstream second parent. Package 15 is not dismissed as
unwanted: the plugin exists upstream. The user clarified that independent parts
may be proposed now, whereas the full plugin must wait until it is complete.
AGENTS.md now records that distinction; that local rule update never enters a PR.

Package 15 was explicitly authorized and extracted from its already-reviewed
upstream-base candidate 5a1a0d97, without the fork forum-crawl integration.
Final evidence and commands: UPSTREAM-HANDOFF-2026-09-05.md.
The user conditionally approved squash without waiting for package 6; its
merge-base 72d1885c remains an ancestor. No package 6 code is included.
