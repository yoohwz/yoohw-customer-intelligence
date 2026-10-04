#!/usr/bin/env python3
"""Exercise owned descendant cleanup without WordPress or any database."""
import importlib.util
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import MagicMock, patch

RUNNER = Path(__file__).resolve().parents[1] / 'scripts/test-isolated.py'
LISTENER = '''import json,os,socket,sys,time
from pathlib import Path
s=socket.socket();s.bind(('127.0.0.1',0));s.listen()
Path(sys.argv[1]).write_text(json.dumps({'pid':os.getpid(),'group':os.getpgrp(),'port':s.getsockname()[1]}))
while True: time.sleep(1)
'''
LEADER = '''import subprocess,sys,time
from pathlib import Path
subprocess.Popen([sys.executable,'-c',sys.argv[1],sys.argv[2]])
while not Path(sys.argv[2]).exists(): time.sleep(.01)
if sys.argv[3]=='wait':
 while True: time.sleep(1)
sys.exit(int(sys.argv[3]))
'''


def load_runner():
    spec = importlib.util.spec_from_file_location('owned_runner', RUNNER)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def receipt(path):
    deadline = time.monotonic() + 5
    while time.monotonic() < deadline:
        try:
            return json.loads(path.read_text())
        except (FileNotFoundError, json.JSONDecodeError):
            time.sleep(.02)
    raise AssertionError('Owned listener did not start')


def listening(port):
    try:
        with socket.create_connection(('127.0.0.1', port), .1):
            return True
    except OSError:
        return False


class OwnedProcessTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='yci-process-control-')
        self.root = Path(self.directory.name)
        self.root.chmod(0o700)
        self.sentinel = subprocess.Popen([sys.executable, '-c', LISTENER, str(self.root / 'sentinel')], start_new_session=True)
        self.sentinel_port = receipt(self.root / 'sentinel')['port']

    def tearDown(self):
        # A failing cleanup control must still stop its known synthetic listener.
        child_path = self.root / 'child'
        if child_path.exists():
            child = receipt(child_path)
            try:
                if os.getpgid(child['pid']) == child['group'] and child['group'] != os.getpgrp():
                    os.kill(child['pid'], signal.SIGKILL)
            except ProcessLookupError:
                pass
        os.killpg(self.sentinel.pid, signal.SIGTERM)
        self.sentinel.wait(timeout=5)
        self.directory.cleanup()
        self.assertFalse(self.root.exists())

    def assert_stopped(self, path):
        port = receipt(path)['port']
        deadline = time.monotonic() + 5
        while listening(port) and time.monotonic() < deadline:
            time.sleep(.02)
        self.assertFalse(listening(port), 'Owned grandchild listener survived')
        self.assertIsNone(self.sentinel.poll())
        self.assertTrue(listening(self.sentinel_port), 'Unrelated listener was terminated')

    def command(self, mode):
        return [sys.executable, '-c', LEADER, LISTENER, str(self.root / 'child'), mode]

    def test_normal_exit_stops_remaining_grandchild(self):
        load_runner().run_owned(self.command('0'))
        self.assert_stopped(self.root / 'child')

    def test_failure_stops_remaining_grandchild(self):
        with self.assertRaises(subprocess.CalledProcessError) as error:
            load_runner().run_owned(self.command('7'))
        self.assertEqual(7, error.exception.returncode)
        self.assert_stopped(self.root / 'child')

    def test_darwin_permission_error_allows_only_absent_or_zombie_group(self):
        module = load_runner()
        process = MagicMock(pid=313371)
        process.wait.return_value = 0
        with patch.object(module.sys, 'platform', 'darwin'), patch.object(module.subprocess, 'Popen', return_value=process), patch.object(module.os, 'killpg', side_effect=PermissionError), patch.object(module, 'run', return_value=subprocess.CompletedProcess([], 0, '313371 Z\n313372 S\n')):
            module.run_owned(['synthetic'])

    def test_darwin_permission_error_does_not_mask_living_group(self):
        module = load_runner()
        process = MagicMock(pid=313371)
        process.wait.return_value = 0
        with patch.object(module.sys, 'platform', 'darwin'), patch.object(module.subprocess, 'Popen', return_value=process), patch.object(module.os, 'killpg', side_effect=PermissionError), patch.object(module, 'run', return_value=subprocess.CompletedProcess([], 0, '313371 S\n')):
            with self.assertRaises(PermissionError):
                module.run_owned(['synthetic'])

    def test_runner_sigterm_stops_group_and_removes_private_directory(self):
        surrogate = '''import importlib.util,signal,sys,tempfile
from pathlib import Path
spec=importlib.util.spec_from_file_location('runner',sys.argv[1]);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
def interrupted(signum,frame): raise RuntimeError('synthetic interruption')
signal.signal(signal.SIGTERM,interrupted)
try:
 with tempfile.TemporaryDirectory(prefix='yci-signal-control-') as directory:
  Path(sys.argv[2]).write_text(directory)
  m.run_owned(sys.argv[3:])
except RuntimeError: sys.exit(23)
'''
        process = subprocess.Popen([sys.executable, '-c', surrogate, str(RUNNER), str(self.root / 'private-root')] + self.command('wait'), start_new_session=True)
        try:
            receipt(self.root / 'child')
            private_root = Path((self.root / 'private-root').read_text())
            self.assertTrue(private_root.is_dir())
            process.send_signal(signal.SIGTERM)
            self.assertEqual(23, process.wait(timeout=10))
            self.assertFalse(private_root.exists())
            self.assert_stopped(self.root / 'child')
        finally:
            if process.poll() is None:
                process.send_signal(signal.SIGTERM)
                process.wait(timeout=10)


if __name__ == '__main__':
    unittest.main()
