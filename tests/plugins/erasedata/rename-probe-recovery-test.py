#!/usr/bin/env python3
"""Eight real probes recover 32 dead workspaces at the lock-name removal cut."""
import argparse
import json
import os
from pathlib import Path
import signal
import subprocess
import tempfile
import time


def dead_workspaces(parent):
    for index in range(32):
        work = parent / ('.rutorrent-rename-probe-999-%016x' % index)
        work.mkdir(mode=0o700)
        for name in ('.lock', 'from-file', 'to-file'):
            (work / name).write_bytes(b'' if name == '.lock' else b'interrupted probe')
            (work / name).chmod(0o600)
        for name in ('from-dir', 'to-dir'):
            (work / name).mkdir(mode=0o700)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('binary')
    parser.add_argument('cut_binary', nargs='?')
    args = parser.parse_args()
    processes = []
    result = {}
    with tempfile.TemporaryDirectory(prefix='probe-recovery-') as path:
        parent = Path(path)
        (parent / 'payload').write_bytes(b'payload must survive')
        dead_workspaces(parent)
        try:
            first = subprocess.Popen([args.cut_binary or args.binary, '--probe', path],
                                     stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            processes.append(first)
            if args.cut_binary:
                deadline = time.monotonic() + 5
                while time.monotonic() < deadline:
                    if first.poll() is not None:
                        raise RuntimeError('cut helper exited before the recovery boundary')
                    status = Path('/proc/%d/status' % first.pid).read_text()
                    if any(line.startswith('State:') and 'T' in line for line in status.splitlines()):
                        break
                    time.sleep(0.01)
                else:
                    raise RuntimeError('cut helper never reached the recovery boundary')
            for _ in range(7):
                processes.append(subprocess.Popen([args.binary, '--probe', path],
                                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE))
            if args.cut_binary:
                # Give competing helpers the open-lock-name window, then let
                # the original owner finish. Correct probes wait for its lock.
                time.sleep(0.15)
                result['competitors_completed_before_owner'] = sum(p.poll() is not None for p in processes[1:])
                os.kill(first.pid, signal.SIGCONT)
            outcomes = []
            for process in processes:
                out, err = process.communicate(timeout=10)
                outcomes.append({'status': process.returncode, 'stderr': err.decode().strip()})
            result.update(probes=outcomes, debris=[p.name for p in parent.glob('.rutorrent-rename-probe-*')],
                          payload=(parent / 'payload').read_text())
            print(json.dumps(result, sort_keys=True))
            assert all(p['status'] == 0 for p in outcomes), 'all eight recovery probes must make progress'
            assert not result['debris'], 'only attested dead probe workspaces are recovered'
            assert result['payload'] == 'payload must survive', 'recovery cannot touch payload entries'
            assert result.get('competitors_completed_before_owner', 0) == 0, 'competing recovery must wait for the live owner'
        finally:
            for process in processes:
                if process.poll() is None:
                    process.kill()
                process.wait()


if __name__ == '__main__':
    main()
