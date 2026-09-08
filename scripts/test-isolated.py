#!/usr/bin/env python3
"""Run existing integration tests using an owned socket-only MySQL instance."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import signal
import subprocess
import tarfile
import tempfile
import time
import urllib.request
import zipfile
import xml.etree.ElementTree as ET

REPO = Path(__file__).resolve().parents[1]
INPUTS = {
    'wordpress.tar.gz': ('https://wordpress.org/wordpress-6.9.tar.gz', '5b36390233e32fef68cb5f66435bb32bdd50e0b3dfa5750aceb2de3c5993d720'),
    'tests.tar.gz': ('https://codeload.github.com/WordPress/wordpress-develop/tar.gz/refs/tags/6.9.0', '7efcd778ada9ffcdb1c6f73d2d859b9d2965a15a1d1ba398a02da5458bb82d7b'),
    'woocommerce.zip': ('https://downloads.wordpress.org/plugin/woocommerce.10.8.0.zip', '37acec830f566941e561e7e8da95b3b8182ac24f77513905e0ad15842ed11f5b'),
}


def run(args, **kwargs):
    return subprocess.run([str(a) for a in args], check=True, **kwargs)


def unpack(root, cache):
    for name, (url, checksum) in INPUTS.items():
        archive = cache / name if cache else root / name
        if not cache:
            urllib.request.urlretrieve(url, archive)
        if hashlib.sha256(archive.read_bytes()).hexdigest() != checksum:
            raise RuntimeError('Dependency checksum mismatch: ' + name)
        if name.endswith('.zip'):
            with zipfile.ZipFile(archive) as z:
                for item in z.infolist():
                    if not (root / item.filename).resolve().is_relative_to(root):
                        raise RuntimeError('Unsafe archive path')
                z.extractall(root)
        else:
            with tarfile.open(archive) as t:
                for item in t.getmembers():
                    if not (item.isfile() or item.isdir()) or not (root / item.name).resolve().is_relative_to(root):
                        raise RuntimeError('Unsafe archive entry')
                t.extractall(root, **({'filter': 'data'} if hasattr(tarfile, 'data_filter') else {}))
    shutil.move(root / 'wordpress-develop-6.9.0/tests/phpunit', root / 'tests')
    shutil.copyfile(REPO / 'tests/wp-tests-config.php', root / 'wp-tests-config.php')


def controls(root, env, php, sql, credentials):
    """Every rejection runs the real entrypoints with synthetic mutation sentinels."""
    marker = root / 'bootstrap-mutated'
    paths = [root / 'tests/includes/functions.php', root / 'tests/includes/bootstrap.php']
    originals = [p.read_bytes() for p in paths]
    for p in paths:
        p.write_text('<?php file_put_contents(' + repr(str(marker)) + ', "mutated"); exit(0);')
    credential_file = root / 'environment.json'
    entrypoints = [REPO / path for path in ('tests/bootstrap.php', 'tests/integration/test-yoohw-cos-smoke.php', 'tests/integration/test-reset-link-integrity.php', 'tests/reset-worker.php')]
    cases = [({}, 'missing root'), ({'YCI_TEST_TOKEN': ''}, 'missing token'),
             ({'YCI_TEST_TOKEN': 'false'}, 'false token'), ({'YCI_TEST_TOKEN': '0' * 64}, 'wrong token'),
             ({'WP_TESTS_DIR': '/tmp/wordpress-tests-lib'}, 'wrong test path'),
             ({'WC_PLUGIN_FILE': '/tmp/woocommerce.php'}, 'wrong plugin path'),
             ({'WC_HPOS_ENABLED': ''}, 'missing mode'), ({'WC_HPOS_ENABLED': 'false'}, 'false mode')]
    try:
        for changes, label in cases:
            candidate = env.copy()
            if label == 'missing root':
                candidate.pop('YCI_TEST_ROOT')
            candidate.update(changes)
            for entry in entrypoints:
                result = subprocess.run([php, str(entry)], env=candidate, capture_output=True)
                if result.returncode == 0 or marker.exists():
                    raise RuntimeError('Environment rejection failed: ' + label)
        for label in ('wrong database', 'wrong ownership', 'modified config', 'global grants', 'predefined database', 'missing credentials', 'false credentials'):
            altered = credentials.copy()
            if label == 'wrong database':
                altered['database'] = 'yci' + '0' * 24
            if label == 'wrong ownership':
                sql("UPDATE " + credentials['database'] + ".yci_environment_owner SET token='wrong'")
            if label == 'modified config':
                with (root / 'wp-tests-config.php').open('a') as f:
                    f.write('\nfile_put_contents(' + repr(str(marker)) + ', \"mutated\");\n')
            if label == 'global grants':
                sql("GRANT SELECT ON *.* TO '" + credentials['database'] + "'@'localhost'")
            credential_file.write_text(json.dumps(altered))
            if label == 'missing credentials':
                credential_file.unlink()
            if label == 'false credentials':
                credential_file.write_text('false')
            for entry in entrypoints:
                command = [php, str(entry)]
                if label == 'predefined database':
                    command = [php, '-r', "define('DB_NAME','synthetic_sentinel'); require " + repr(str(entry)) + ';']
                result = subprocess.run(command, env=env, capture_output=True)
                if result.returncode == 0 or marker.exists():
                    raise RuntimeError('Environment rejection failed: ' + label)
            credential_file.write_text(json.dumps(credentials))
            credential_file.chmod(0o600)
            shutil.copyfile(REPO / 'tests/wp-tests-config.php', root / 'wp-tests-config.php')
            sql("UPDATE " + credentials['database'] + ".yci_environment_owner SET token='" + credentials['token'] + "'")
            if label == 'global grants':
                sql("REVOKE SELECT ON *.* FROM '" + credentials['database'] + "'@'localhost'")
        account = "'" + credentials['database'] + "'@'localhost'"
        original_grants = sql('SHOW GRANTS FOR ' + account)
        for scope, privilege in (('*.*', 'USAGE'), (credentials['database'] + '.*', 'ALL PRIVILEGES')):
            sql('GRANT ' + privilege + ' ON ' + scope + ' TO ' + account + ' WITH GRANT OPTION')
            try:
                expected = 'GRANT ' + privilege + ' ON ' + ('*.*' if scope == '*.*' else '`' + credentials['database'] + '`.*') + ' TO '
                rows = sql('SHOW GRANTS FOR ' + account).splitlines()
                if not any(row.startswith(expected) and 'WITH GRANT OPTION' in row for row in rows):
                    raise RuntimeError('Grant-option fixture did not establish the expected live privilege')
                for entry in entrypoints:
                    result = subprocess.run([php, str(entry)], env=env, capture_output=True)
                    if result.returncode == 0 or marker.exists() or b'database privileges exceed owned database' not in result.stdout + result.stderr:
                        raise RuntimeError('Grant-option rejection failed before bootstrap: ' + scope + ' / ' + entry.name)
                print('PASS: live ' + scope + ' GRANT OPTION rejected at all guarded entrypoints before bootstrap', flush=True)
            finally:
                sql('REVOKE GRANT OPTION ON ' + scope + ' FROM ' + account)
                if sql('SHOW GRANTS FOR ' + account) != original_grants:
                    raise RuntimeError('Fixture account privileges were not restored exactly')
        if sql('SELECT value FROM synthetic_sentinel.untouched') != credentials['token']:
            raise RuntimeError('Unrelated synthetic sentinel changed during rejection controls')
        run([php, '-r', "require " + repr(str(REPO / 'tests/environment.php')) + "; yci_test_environment(); wp_mail('fixture@example.test','probe','fixture'); if (($GLOBALS['yci_intercepted_mail'] ?? 0) !== 1) { exit(1); }"], env=env)
        print('PASS: 68 entrypoint rejection controls; valid ownership/grants; early mail interception', flush=True)
    finally:
        for p, original in zip(paths, originals):
            p.write_bytes(original)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mysql-bin', type=Path, required=True, help='Directory containing MySQL 8 mysqld and mysql')
    parser.add_argument('--mysqld', type=Path, help='Server binary when installed outside mysql-bin')
    parser.add_argument('--inputs', type=Path, help='Optional cache of checksum-verified dependency archives')
    parser.add_argument('--php', default=shutil.which('php'))
    parser.add_argument('--mode', choices=['yes', 'no', 'both'], default='both')
    args = parser.parse_args()
    # Do not propagate WordPress/database settings or PHP auto-prepend configuration.
    env = {k: v for k, v in os.environ.items() if not k.startswith(('WP_', 'WC_', 'YCI_', 'DB_', 'MYSQL', 'PHPRC', 'PHP_INI_SCAN_DIR'))}
    def interrupted(signum, frame):
        raise RuntimeError('Interrupted; cleaning owned test resources')
    signal.signal(signal.SIGTERM, interrupted)
    with tempfile.TemporaryDirectory(prefix='yci-test-', dir='/tmp') as directory:
        root = Path(directory).resolve()
        root.chmod(0o700)
        # MySQL 8.0 reads login files even with --no-defaults; use only our private path.
        env['MYSQL_TEST_LOGIN_FILE'] = str(root / 'no-login.cnf')
        server = None
        try:
            unpack(root, args.inputs)
            mysql = args.mysql_bin.resolve() / 'mysql'
            mysqld = args.mysqld.resolve() if args.mysqld else args.mysql_bin.resolve() / 'mysqld'
            run([mysqld, '--no-defaults', '--initialize-insecure', '--datadir=' + str(root / 'data')], stdout=subprocess.DEVNULL, env=env)
            log = (root / 'mysql.log').open('w')
            server = subprocess.Popen([str(mysqld), '--no-defaults', '--datadir=' + str(root / 'data'), '--socket=' + str(root / 'mysql.sock'), '--pid-file=' + str(root / 'mysql.pid'), '--skip-networking', '--mysqlx=OFF'], stdout=log, stderr=log, env=env)
            def sql(query):
                return run([mysql, '--no-defaults', '--protocol=SOCKET', '--socket=' + str(root / 'mysql.sock'), '-uroot', '-N', '-B'], input=query, text=True, capture_output=True, env=env).stdout.strip()
            for attempt in range(100):
                if server.poll() is not None:
                    raise RuntimeError((root / 'mysql.log').read_text())
                try:
                    sql('SELECT 1')
                    break
                except subprocess.CalledProcessError:
                    time.sleep(0.2)
            else:
                raise RuntimeError('Owned MySQL failed to start')
            credentials = dict(database='yci' + secrets.token_hex(12), password=secrets.token_hex(32), token=secrets.token_hex(32))
            name, password, token = credentials.values()
            sql(f"CREATE DATABASE {name}; CREATE USER '{name}'@'localhost' IDENTIFIED BY '{password}'; GRANT ALL ON {name}.* TO '{name}'@'localhost'; CREATE TABLE {name}.yci_environment_owner (token varchar(64)); INSERT INTO {name}.yci_environment_owner VALUES ('{token}'); CREATE DATABASE synthetic_sentinel; CREATE TABLE synthetic_sentinel.untouched (value varchar(64)); INSERT INTO synthetic_sentinel.untouched VALUES ('{token}');")
            (root / 'environment.json').write_text(json.dumps(credentials))
            (root / 'environment.json').chmod(0o600)
            env.update(YCI_TEST_ROOT=str(root), YCI_TEST_TOKEN=token, YCI_TEST_GUARD=str(REPO / 'tests/environment.php'), WP_TESTS_DIR=str(root / 'tests'), WC_PLUGIN_FILE=str(root / 'woocommerce/woocommerce.php'), WC_HPOS_ENABLED='yes', WP_TESTS_PHPUNIT_POLYFILLS_PATH=str(REPO / 'vendor/yoast/phpunit-polyfills'))
            controls(root, env, args.php, sql, credentials)
            for mode in ['yes', 'no'] if args.mode == 'both' else [args.mode]:
                env['WC_HPOS_ENABLED'] = mode
                report = root / ('junit-' + mode + '.xml')
                run([args.php, '-d', 'disable_functions=mail', REPO / 'vendor/bin/phpunit', '-c', REPO / 'phpunit.xml.dist', '--fail-on-skipped', '--fail-on-risky', '--log-junit', report], env=env, cwd=REPO)
                suite = ET.parse(report).getroot()
                cases = suite.findall('.//testcase')
                if not cases or suite.findall('.//skipped') or suite.findall('.//failure') or suite.findall('.//error'):
                    raise RuntimeError('Empty or incomplete integration evidence')
                print(f'PASS: HPOS={mode}, {len(cases)} tests, WP 6.9 / WC 10.8.0 / PHP runtime above', flush=True)
                if sql('SELECT value FROM synthetic_sentinel.untouched') != token:
                    raise RuntimeError('Unrelated synthetic sentinel changed')
            print('PASS: persisted unrelated synthetic database sentinel unchanged', flush=True)
        finally:
            if server is not None:
                server.terminate()
                try:
                    server.wait(timeout=20)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait()
        # TemporaryDirectory removes only this invocation's private directory.
    if root.exists():
        raise RuntimeError('Owned resource cleanup failed')
    print('PASS: owned MySQL stopped and temporary filesystem removed', flush=True)


if __name__ == '__main__':
    main()
