#!/usr/bin/env python3
"""High-leverage contracts for the YCI release-only WordPress.org publisher."""
from __future__ import annotations

import ast
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def syntax_contract() -> None:
    for path in (
        ".github/scripts/release_lib.py",
        ".github/scripts/release_cli.py",
        "tests/release-contract-tests.py",
    ):
        ast.parse(text(path), filename=path)


def workflow_contract() -> None:
    prepare = text(".github/workflows/release-prepare.yml")
    publish = text(".github/workflows/publish-wordpress-org.yml")
    context = text(".github/actions/publisher-context/action.yml")

    for workflow in (prepare, publish):
        assert "workflow_dispatch:" in workflow
        assert "pull_request:" not in workflow
        assert "push:" not in workflow
        assert "GITHUB_REF_PROTECTED" in workflow or "publisher-context" in workflow

    trusted_stage = "cp -- control/scripts/stage-distribution.sh candidate-data/scripts/stage-distribution.sh"
    for required in (
        "test \"$CANDIDATE_SHA\" = \"$GITHUB_SHA\"",
        "Replace candidate staging helper with trusted control helper",
        trusted_stage,
        "release_cli.py prepare",
        "Plugin Check exact prepared WordPress.org payload",
        "build-dir: ${{ runner.temp }}/yci-release/rc/payload",
        "include-hidden-files: true",
        "strict: false",
        "yci-release/rc/*",
        "retention-days: 90",
    ):
        assert required in prepare, required

    for required in (
        "name: Publish Customer Intelligence to WordPress.org",
        "options: [publish, verify-only]",
        "default: true",
        "preparation_run_id:",
        "original_publish_run_id:",
        "wordpress-org-production",
        "Read-only WordPress.org publication preflight",
        "Dry-run final remote recheck",
        "Human-gated atomic WordPress.org publication",
        "Assert production Environment configuration before mutation",
        "Seal immutable annotated release Git tag",
        "Read-only WordPress.org recovery and propagation check",
        "GitHub Release only after verified WordPress.org publication",
        "release_cli.py recheck",
        "release_cli.py seal",
        "release_cli.py commit",
        "release_cli.py verify",
        "release_cli.py recover",
        "release_cli.py release",
    ):
        assert required in publish, required

    assert publish.count("secrets.WPORG_SVN_PASSWORD") == 1
    assert "secrets.WPORG_SVN_PASSWORD" not in prepare
    assert "secrets.WPORG_SVN_PASSWORD" not in context
    assert "WPORG_SVN_PASSWORD" not in context

    commit_step = """      - name: Single atomic SVN commit attempt
        id: commit
        env:
          GH_TOKEN: ${{ github.token }}
          WPORG_SVN_USERNAME: ${{ vars.WPORG_SVN_USERNAME }}
          WPORG_SVN_PASSWORD: ${{ secrets.WPORG_SVN_PASSWORD }}
        run: python3 control/.github/scripts/release_cli.py commit"""
    assert commit_step in publish

    for required in (
        "test \"$GITHUB_REPOSITORY\" = yoohwz/yoohw-customer-intelligence",
        "test \"$GITHUB_REF\" = refs/heads/main",
        "test \"$GITHUB_REF_PROTECTED\" = true",
        "publish-wordpress-org.yml@refs/heads/main",
        "Replace candidate staging helper with trusted control helper",
        trusted_stage,
        "actions/download-artifact@d3f86a106a0bac45b974a628896c90dbdf5c8093",
        "yci-wporg-${{ env.VERSION }}-${{ env.CANDIDATE_SHA }}",
        "subversion rsync",
        "release_cli.py context",
    ):
        assert required in context, required


def implementation_contract() -> None:
    lib = text(".github/scripts/release_lib.py")
    cli = text(".github/scripts/release_cli.py")

    for required in (
        'REPOSITORY = "yoohwz/yoohw-customer-intelligence"',
        'SLUG = "yoohw-customer-intelligence"',
        'PLUGIN_FILE = "yoohw-customer-intelligence.php"',
        'PRODUCT_NAME = "YoOhw Customer Intelligence for WooCommerce"',
        'SVN_URL = f"https://plugins.svn.wordpress.org/{SLUG}"',
        'EXPECTED_SVN_AUTHOR = "yoohw"',
        "YOOHW_COS_VERSION",
        "SVN_APPROVAL_SNAPSHOT_KEYS",
        "svn_approval_identity",
        "plugin_relative_svn_path",
        'prefix = f"/{SLUG}/"',
        "scripts/stage-distribution.sh",
        "zipfile.ZIP_STORED",
        "date_time=(1980, 1, 1, 0, 0, 0)",
        '"git/tags"',
        '"tagger"',
        "existing release tag is not annotated",
        "decode_json=False",
        "tag-only release is unsupported",
        "--password-from-stdin",
        "--no-auth-cache",
        'remove_env={"WPORG_SVN_PASSWORD"}',
        "unexpected WordPress.org SVN username",
        "release was authored by an unexpected committer",
        "WordPress.org assets are immutable in normal publication",
        "SVN commit outcome is unknown; do not retry publication, use verify-only recovery",
        "downloads.wordpress.org/plugin/",
        "WPORG_PROPAGATION_PENDING",
        "WPORG_PUBLIC_RELEASE_VERIFIED",
    ):
        assert required in lib, required

    assert "Support Portal" not in lib
    assert '"root_revision"' not in lib
    assert '["svn", "info", "--show-item", "revision", "."]' not in lib
    assert '"--password",' not in lib

    for command in (
        "prepare",
        "context",
        "preflight",
        "recheck",
        "seal",
        "commit",
        "verify",
        "recover",
        "release",
    ):
        assert f'"{command}":' in cli, command
    assert "PUBLISH_ENVIRONMENT" in cli
    assert "wordpress-org-production" in cli
    assert "dry-run cannot mutate external state" in cli
    assert "tag_object_sha" in cli


def documentation_contract() -> None:
    docs = text("docs/releasing.md")
    for required in (
        "# Customer Intelligence — WordPress.org release",
        "Publish Customer Intelligence to WordPress.org",
        "wordpress-org-production",
        "WPORG_SVN_USERNAME",
        "exact value `yoohw`",
        "WPORG_SVN_PASSWORD",
        "SVN-specific password",
        "candidate staging helper with the trusted control-plane helper",
        "YOOHW_COS_VERSION",
        "annotated",
        "dry_run=true",
        "dry_run=false",
        "verify-only",
        "SVN commit outcome is unknown",
        "WPORG_PROPAGATION_PENDING",
        "WPORG_PUBLIC_RELEASE_VERIFIED",
        "GitHub Release",
        "does not publish any version automatically",
    ):
        assert required in docs, required


def load_release_lib():
    path = ROOT / ".github/scripts/release_lib.py"
    spec = importlib.util.spec_from_file_location("yci_release_lib_contract", path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def deterministic_package_contract() -> None:
    rel = load_release_lib()
    with tempfile.TemporaryDirectory(prefix="yci-release-contract-") as temporary:
        root = Path(temporary)
        payload = root / "payload"
        (payload / "includes").mkdir(parents=True)
        (payload / "readme.txt").write_text("release contract\n", encoding="utf-8")
        (payload / "includes/example.php").write_text("<?php echo 'ok';\n", encoding="utf-8")
        first = root / "first.zip"
        second = root / "second.zip"
        rel.deterministic_zip(payload, first)
        rel.deterministic_zip(payload, second)
        assert first.read_bytes() == second.read_bytes()
        extracted = rel.safe_extract_product(first, root / "extracted")
        assert rel.tree_digest(extracted) == rel.tree_digest(payload)

        malicious = root / "malicious.zip"
        with zipfile.ZipFile(malicious, "w") as archive:
            archive.writestr("yoohw-customer-intelligence/../escape.txt", "no")
        try:
            rel.safe_extract_product(malicious, root / "malicious-out")
        except rel.ReleaseError:
            pass
        else:
            raise AssertionError("ZIP traversal fixture was not rejected")


def credential_environment_contract() -> None:
    rel = load_release_lib()
    old = os.environ.get("WPORG_SVN_PASSWORD")
    os.environ["WPORG_SVN_PASSWORD"] = "must-not-reach-child"
    try:
        result = rel.run(
            [
                "python3",
                "-c",
                "import os; print('present' if 'WPORG_SVN_PASSWORD' in os.environ else 'absent')",
            ],
            remove_env={"WPORG_SVN_PASSWORD"},
        )
        assert result.stdout.strip() == "absent"
    finally:
        if old is None:
            os.environ.pop("WPORG_SVN_PASSWORD", None)
        else:
            os.environ["WPORG_SVN_PASSWORD"] = old


def release_asset_bytes_contract() -> None:
    rel = load_release_lib()
    payload = b'{"schema_version":1,"state":"manifest"}\n'

    class FakeResponse:
        status = 200
        headers = {"Content-Type": "application/octet-stream"}

        def __enter__(self):
            return self

        def __exit__(self, exc_type, exc, tb):
            return False

        def read(self):
            return payload

    original_urlopen = rel.urllib.request.urlopen
    rel.urllib.request.urlopen = lambda request, timeout=30: FakeResponse()
    try:
        result = rel.GitHubAPI("contract-token")._release_asset_bytes(123)
        assert isinstance(result, bytes)
        assert result == payload
    finally:
        rel.urllib.request.urlopen = original_urlopen


def existing_release_manifest_asset_contract() -> None:
    rel = load_release_lib()
    candidate = "c" * 40
    version = "1.3.1"
    with tempfile.TemporaryDirectory(prefix="yci-existing-release-") as temporary:
        prepared = Path(temporary)
        package_name = f"{rel.SLUG}-{version}.zip"
        package = prepared / package_name
        manifest_path = prepared / "release-manifest.json"
        package.write_bytes(b"package-bytes")
        manifest_path.write_bytes(b'{"schema_version":1,"existing":true}\n')
        manifest = {
            "version": version,
            "candidate_sha": candidate,
            "package_name": package_name,
        }

        api = rel.GitHubAPI("contract-token")
        api.resolve_tag_commit = lambda requested: candidate
        api.get_optional = lambda path: {"id": 77, "draft": False, "prerelease": False}

        asset_bytes = {
            101: package.read_bytes(),
            102: manifest_path.read_bytes(),
        }

        def fake_get(path: str):
            assert path == "releases/77/assets?per_page=100"
            return [
                {"name": package_name, "id": 101},
                {"name": "release-manifest.json", "id": 102},
            ]

        api.get = fake_get
        api._release_asset_bytes = lambda asset_id: asset_bytes[asset_id]

        def unexpected_upload(*args, **kwargs):
            raise AssertionError("existing release assets must be reconciled, not re-uploaded")

        api._upload_asset = unexpected_upload
        assert api.create_or_reconcile_release(manifest, prepared) == 77


def full_prepare_contract() -> None:
    rel = load_release_lib()
    candidate_sha = rel.git_head(ROOT)
    version = rel.version_from_tree(ROOT)
    with tempfile.TemporaryDirectory(prefix="yci-release-prepare-") as temporary:
        work = Path(temporary)
        artifact_name, manifest = rel.prepare_release(ROOT, work, candidate_sha, version, 123456)
        prepared = work / "rc"
        assert artifact_name == f"yci-wporg-{version}-{candidate_sha}"
        assert manifest["candidate_sha"] == candidate_sha
        assert manifest["version"] == version
        assert manifest["file_count"] > 0
        assert (prepared / "payload/yoohw-customer-intelligence.php").is_file()
        assert (prepared / "payload/readme.txt").is_file()
        assert (prepared / "payload/uninstall.php").is_file()
        assert (prepared / manifest["package_name"]).is_file()
        loaded = rel.load_prepared(prepared, candidate_sha, version, 123456)
        assert loaded == manifest


def svn_snapshot_scope_contract() -> None:
    rel = load_release_lib()
    yci_state = {
        "trunk_revision": "3693807",
        "trunk_tree_sha256": "a" * 64,
        "assets_revision": "3693001",
        "assets_tree_sha256": "b" * 64,
        "target_tag_exists": False,
    }
    approved = {"repository_revision": "3694483", **yci_state}
    current = {"repository_revision": "3694999", **yci_state}

    assert rel.svn_approval_identity(approved) == rel.svn_approval_identity(current)

    changed = dict(current)
    changed["trunk_revision"] = "3695000"
    assert rel.svn_approval_identity(approved) != rel.svn_approval_identity(changed)


def svn_stage_tag_only_contract() -> None:
    rel = load_release_lib()
    version = "1.3.1"
    with tempfile.TemporaryDirectory(prefix="yci-svn-stage-") as temporary:
        root = Path(temporary)
        workspace = root / "svn"
        payload = root / "payload"
        (workspace / "trunk").mkdir(parents=True)
        (workspace / "tags").mkdir(parents=True)
        payload.mkdir()
        (payload / "readme.txt").write_text("payload\n", encoding="utf-8")

        def snapshot(_version: str):
            return {
                "trunk_revision": "1",
                "trunk_tree_sha256": "a" * 64,
                "assets_revision": None,
                "assets_tree_sha256": None,
                "target_tag_exists": False,
            }

        original_run = rel.run
        rel.run = lambda args, **kwargs: subprocess.CompletedProcess(args, 0, stdout="", stderr="")
        try:
            repo = rel.SVNWorkspace(workspace)
            repo.snapshot = snapshot
            status_calls = iter([[], [f"A       tags/{version}"]])
            repo._status = lambda: next(status_calls)
            try:
                repo.stage(payload, version)
            except rel.ReleaseError as error:
                assert "tag-only release is unsupported" in str(error)
            else:
                raise AssertionError("tag-only SVN staging was not rejected before mutation")

            repo = rel.SVNWorkspace(workspace)
            repo.snapshot = snapshot
            status_calls = iter(
                [
                    [],
                    ["M       trunk/readme.txt", f"A       tags/{version}"],
                ]
            )
            repo._status = lambda: next(status_calls)
            staged = repo.stage(payload, version)
            assert staged["changed_paths"] == [f"tags/{version}", "trunk/readme.txt"]
        finally:
            rel.run = original_run


def svn_log_namespace_contract() -> None:
    rel = load_release_lib()
    version = "1.3.1"
    candidate = "c" * 40
    run_id = 123456
    message = f"Release {rel.SLUG} {version} from {candidate} (GitHub run {run_id})"

    def xml(paths: list[tuple[str, str]]) -> str:
        rendered = "\n".join(
            f'<path action="{action}">{path}</path>' for action, path in paths
        )
        return (
            '<?xml version="1.0"?>\n'
            '<log>\n'
            '<logentry revision="3695001">\n'
            '<author>yoohw</author>\n'
            '<paths>\n'
            f'{rendered}\n'
            '</paths>\n'
            f'<msg>{message}</msg>\n'
            '</logentry>\n'
            '</log>\n'
        )

    original_run = rel.run

    def evaluate(paths: list[tuple[str, str]]):
        payload = xml(paths)

        def fake_run(args, **kwargs):
            return subprocess.CompletedProcess(args, 0, stdout=payload, stderr="")

        rel.run = fake_run
        try:
            return rel.svn_publication_log(version, candidate, run_id)
        finally:
            rel.run = original_run

    valid = evaluate(
        [
            ("M", f"/{rel.SLUG}/trunk/readme.txt"),
            ("A", f"/{rel.SLUG}/tags/{version}"),
        ]
    )
    assert valid["plugin_relative_changed_paths"] == [f"/tags/{version}", "/trunk/readme.txt"]

    for invalid_paths, message_fragment in (
        (
            [
                ("M", f"/{rel.SLUG}/trunk/readme.txt"),
                ("A", f"/{rel.SLUG}/tags/{version}"),
                ("M", f"/{rel.SLUG}/assets/banner-1544x500.png"),
            ],
            "changed assets",
        ),
        (
            [
                ("M", f"/{rel.SLUG}/trunk/readme.txt"),
                ("A", f"/{rel.SLUG}/tags/{version}"),
                ("M", "/another-plugin/trunk/readme.txt"),
            ],
            "outside yoohw-customer-intelligence",
        ),
        (
            [
                ("M", f"/{rel.SLUG}/trunk/readme.txt"),
                ("M", f"/{rel.SLUG}/tags/1.3.0/readme.txt"),
            ],
            "unexpected plugin path",
        ),
    ):
        try:
            evaluate(invalid_paths)
        except rel.ReleaseError as error:
            assert message_fragment in str(error)
        else:
            raise AssertionError(f"invalid SVN log paths were accepted: {invalid_paths}")


def main() -> None:
    syntax_contract()
    workflow_contract()
    implementation_contract()
    documentation_contract()
    deterministic_package_contract()
    credential_environment_contract()
    release_asset_bytes_contract()
    existing_release_manifest_asset_contract()
    full_prepare_contract()
    svn_snapshot_scope_contract()
    svn_stage_tag_only_contract()
    svn_log_namespace_contract()
    print("release-contracts-ok")


if __name__ == "__main__":
    main()
