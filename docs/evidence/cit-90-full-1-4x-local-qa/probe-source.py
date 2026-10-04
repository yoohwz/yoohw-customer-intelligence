#!/usr/bin/env python3
"""Read-only local source gate. Does not bootstrap WordPress or access its database."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess

REPO = Path(__file__).resolve().parents[3]
BASE = 'f44ff6b22eb799cc934e732a70f1fc852f08db99'
parser = argparse.ArgumentParser()
parser.add_argument('--plugin-root', type=Path, required=True)
args = parser.parse_args()


def git(*arguments):
    return subprocess.check_output(['git', *arguments], cwd=REPO)


files = git('ls-tree', '-r', '--name-only', BASE).decode().splitlines()
files = [name for name in files if name.startswith(('includes/', 'admin/', 'assets/', 'templates/', 'languages/'))
         or name == 'yoohw-customer-intelligence.php']
missing, different, matches = [], [], 0
for name in files:
    target = args.plugin_root / name
    if not target.is_file():
        missing.append(name)
    elif target.read_bytes() != git('show', BASE + ':' + name):
        different.append(name)
    else:
        matches += 1
representatives = ['yoohw-customer-intelligence.php',
                   'includes/class-yoohw-cos-commerce-metrics-policy.php',
                   'includes/class-yoohw-cos-migration-runner.php']
comparisons = {}
for name in representatives:
    target = args.plugin_root / name
    comparisons[name] = {
        'main_sha256': hashlib.sha256(git('show', BASE + ':' + name)).hexdigest(),
        'installed_sha256': hashlib.sha256(target.read_bytes()).hexdigest() if target.is_file() else None,
    }
entry = args.plugin_root / 'yoohw-customer-intelligence.php'
text = entry.read_text() if entry.is_file() else ''
version = re.search(r'^ \* Version: (.+)$', text, re.MULTILINE)
print(json.dumps({
    'admitted_main': BASE,
    'installed_header_version': version.group(1) if version else None,
    'checked_runtime_files': len(files),
    'matching_files': matches,
    'different_files': different,
    'missing_files': missing,
    'representative_hashes': comparisons,
    'source_gate': 'MATCH' if not missing and not different else 'MISMATCH',
}, indent=2))
