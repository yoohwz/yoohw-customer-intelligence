#!/usr/bin/env python3
"""Fail closed: every PR is a full runtime candidate, including docs and helpers."""
import json
import os
import sys
import unittest

REQUIRED = {'syntax', 'integration'}


def passes(needs, classification):
    return classification == 'full' and set(needs) == REQUIRED and all(
        isinstance(needs[job], dict) and needs[job].get('result') == 'success'
        for job in REQUIRED
    )


class GateControls(unittest.TestCase):
    def test_success(self):
        self.assertTrue(passes({key: {'result': 'success'} for key in REQUIRED}, 'full'))

    def test_missing_failure_cancel_skip_unknown(self):
        good = {key: {'result': 'success'} for key in REQUIRED}
        for key in REQUIRED:
            for state in ('failure', 'cancelled', 'skipped', '', 'unknown', None):
                with self.subTest(key=key, state=state):
                    self.assertFalse(passes({**good, key: {'result': state}}, 'full'))
            self.assertFalse(passes({k: v for k, v in good.items() if k != key}, 'full'))
        for classification in ('', 'docs', 'unknown', None):
            self.assertFalse(passes(good, classification))
        self.assertFalse(passes({**good, 'extra': {'result': 'success'}}, 'full'))
        self.assertFalse(passes({}, 'full'))


if __name__ == '__main__':
    if '--self-test' in sys.argv:
        unittest.main(argv=[sys.argv[0]])
    else:
        result = passes(json.loads(os.environ['YCI_NEEDS']), os.environ.get('YCI_CLASSIFICATION'))
        print('YCI Required CI: ' + ('PASS' if result else 'FAIL'))
        sys.exit(0 if result else 1)
