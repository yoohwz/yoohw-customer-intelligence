#!/usr/bin/env python3
"""CIT-88 historical upgrades using the unchanged owned integration provisioner.

No existing installation is accepted. This does not replace the full required suite.
"""
import argparse
import importlib.util
import io
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile

REPO = Path(__file__).resolve().parents[3]
FIXTURES = REPO / 'docs/evidence/cit-86'
HISTORICAL = {
    '1.2.2': '4db849bf93d7c3505b02fab123e8f139935f0b47',
    '1.3.0': 'fed2c8ec4010d01a24a3d69bbb6d9929b2d0ccc1',
    '1.4.0': '1ac1b8c4ab6e6109ea7746b204efc50caf3b5e1f',
    '1.4.1': '0ac831c622b6ecf20323deb4bb88d0b23d2649db',
    '1.4.2': '22cbb91c1ddd4a6c6723cd52c1f2b94485220ece',
}
parser = argparse.ArgumentParser()
parser.add_argument('--output-dir', type=Path, required=True)
args, harness_args = parser.parse_known_args()
args.output_dir.mkdir(parents=True, exist_ok=True)
spec = importlib.util.spec_from_file_location('owned_runner', REPO / 'scripts/test-isolated.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)
original = runner.run

with tempfile.TemporaryDirectory(prefix='cit88-upgrades-') as directory:
    work = Path(directory)
    for tag, commit in HISTORICAL.items():
        identity = subprocess.check_output(['git', 'rev-parse', tag + '^{}'], cwd=REPO, text=True).strip()
        if identity != commit:
            raise RuntimeError('Historical tag identity changed: ' + tag)
        archive = subprocess.check_output(['git', 'archive', commit], cwd=REPO)
        target = (work / tag).resolve()
        target.mkdir()
        with tarfile.open(fileobj=io.BytesIO(archive)) as source:
            for member in source.getmembers():
                if not (member.isfile() or member.isdir()) or not (target / member.name).resolve().is_relative_to(target):
                    raise RuntimeError('Unsafe historical archive member')
            source.extractall(target, **({'filter': 'data'} if hasattr(tarfile, 'data_filter') else {}))

    def probe_run(command, **kwargs):
        command = list(command)
        if any(str(item).endswith('tests/benchmark.php') for item in command):
            kwargs = kwargs.copy()
            kwargs['env'] = dict(kwargs['env'], YCI_BENCHMARK_SIZE='smoke')
            for tag in HISTORICAL:
                for phase in ('seed', 'upgrade'):
                    options = kwargs.copy()
                    options['env'] = kwargs['env'].copy()
                    options['env'].update(CIT86_TAG=tag, CIT86_PHASE=phase,
                                          CIT86_SOURCE=str(work / tag if phase == 'seed' else REPO),
                                          CIT86_HISTORICAL_OUTPUT=str(args.output_dir / 'historical.jsonl'))
                    original([command[0], '-d', 'disable_functions=mail', FIXTURES / 'historical-probe.php'], **options)
        result = original(command, **kwargs)
        if any(str(item).endswith('tests/benchmark.php') for item in command):
            # The supplemental suite has no full-suite teardown. Clean only the guard-owned
            # synthetic WordPress prefix before the next mode; keep ownership/sentinel intact.
            original([command[0], FIXTURES / 'cleanup-owned.php'], env=kwargs['env'])
        return result

    runner.run = probe_run
    sys.argv = ['test-isolated.py'] + harness_args
    sys.argv.append('--benchmark-only')
    runner.main()
