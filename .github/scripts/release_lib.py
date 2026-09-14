#!/usr/bin/env python3
"""Trusted release-only primitives for Customer Intelligence.

Only protected-main release control executes this module. Candidate plugin files are
staged as data through the repository-owned distribution script; candidate PHP or
plugin hooks are never imported by this control plane.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
import zipfile

REPOSITORY = "yoohwz/yoohw-customer-intelligence"
SLUG = "yoohw-customer-intelligence"
PLUGIN_FILE = "yoohw-customer-intelligence.php"
PRODUCT_NAME = "YoOhw Customer Intelligence for WooCommerce"
SVN_URL = f"https://plugins.svn.wordpress.org/{SLUG}"
EXPECTED_SVN_AUTHOR = "yoohw"
PREPARE_WORKFLOW = ".github/workflows/release-prepare.yml"
PUBLISH_WORKFLOW = ".github/workflows/publish-wordpress-org.yml"
VERSION_RE = re.compile(r"^[0-9]+(?:\.[0-9]+){1,2}$")
SHA_RE = re.compile(r"^[0-9a-f]{40}$")
SVN_APPROVAL_SNAPSHOT_KEYS = (
    "trunk_revision",
    "trunk_tree_sha256",
    "assets_revision",
    "assets_tree_sha256",
    "target_tag_exists",
)


class ReleaseError(RuntimeError):
    pass


def require(condition: bool, message: str) -> None:
    if not condition:
        raise ReleaseError(message)


def canonical(value) -> bytes:
    return (json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False) + "\n").encode("utf-8")


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def write_json(path: Path, value) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(canonical(value))


def read_json(path: Path):
    require(path.is_file(), f"missing JSON evidence: {path}")
    return json.loads(path.read_text(encoding="utf-8"))


def run(
    args,
    *,
    cwd: Path | None = None,
    input_text: str | None = None,
    env: dict[str, str] | None = None,
    remove_env: set[str] | None = None,
    check: bool = True,
) -> subprocess.CompletedProcess[str]:
    merged = os.environ.copy()
    if env:
        merged.update(env)
    for key in remove_env or set():
        merged.pop(key, None)
    result = subprocess.run(
        [str(item) for item in args],
        cwd=str(cwd) if cwd else None,
        input=input_text,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=merged,
        check=False,
    )
    if check and result.returncode != 0:
        raise ReleaseError(
            f"command failed ({result.returncode}): {' '.join(map(str, args))}\n{result.stderr.strip()}"
        )
    return result


def git_head(path: Path) -> str:
    return run(["git", "-C", path, "rev-parse", "HEAD"]).stdout.strip()


def version_from_tree(source: Path) -> str:
    plugin = (source / PLUGIN_FILE).read_text(encoding="utf-8")
    readme = (source / "readme.txt").read_text(encoding="utf-8")
    header = re.search(r"^\s*\*?\s*Version:\s*([^\s]+)", plugin, re.MULTILINE)
    stable = re.search(r"^Stable tag:\s*([^\s]+)", readme, re.MULTILINE)
    constant = re.search(
        r"define\(\s*['\"]YOOHW_COS_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)",
        plugin,
    )
    require(header is not None and stable is not None and constant is not None, "Version metadata missing")
    require(header.group(1) == stable.group(1), "plugin Version and readme Stable tag differ")
    require(header.group(1) == constant.group(1), "plugin Version and YOOHW_COS_VERSION differ")
    require(VERSION_RE.fullmatch(header.group(1)) is not None, "release version must be numeric")
    return header.group(1)


def tree_entries(root: Path) -> list[dict[str, object]]:
    require(root.is_dir(), f"tree root missing: {root}")
    entries: list[dict[str, object]] = []
    for path in sorted(root.rglob("*"), key=lambda p: p.relative_to(root).as_posix()):
        relative = path.relative_to(root)
        if ".svn" in relative.parts:
            continue
        if path.is_symlink():
            raise ReleaseError(f"symbolic link not allowed in product tree: {relative}")
        if not path.is_file():
            continue
        entries.append(
            {
                "path": relative.as_posix(),
                "size": path.stat().st_size,
                "sha256": sha256_file(path),
            }
        )
    return entries


def tree_digest(root: Path) -> str:
    return sha256_bytes(canonical({"files": tree_entries(root)}))


def stage(source: Path, destination: Path) -> str:
    require((source / "scripts/stage-distribution.sh").is_file(), "canonical staging script missing")
    if destination.exists():
        shutil.rmtree(destination)
    run(["bash", source / "scripts/stage-distribution.sh", source, destination])
    return tree_digest(destination)


def deterministic_zip(payload: Path, destination: Path) -> None:
    destination.parent.mkdir(parents=True, exist_ok=True)
    if destination.exists():
        destination.unlink()
    with zipfile.ZipFile(destination, "w", compression=zipfile.ZIP_STORED) as archive:
        for item in tree_entries(payload):
            relative = str(item["path"])
            info = zipfile.ZipInfo(f"{SLUG}/{relative}", date_time=(1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = (0o100644 & 0xFFFF) << 16
            info.compress_type = zipfile.ZIP_STORED
            archive.writestr(info, (payload / relative).read_bytes())


def safe_extract_product(zip_path: Path, destination: Path) -> Path:
    if destination.exists():
        shutil.rmtree(destination)
    destination.mkdir(parents=True)
    seen: set[str] = set()
    with zipfile.ZipFile(zip_path, "r") as archive:
        for info in archive.infolist():
            name = info.filename
            require(name not in seen, f"duplicate ZIP member: {name}")
            seen.add(name)
            require("\\" not in name and not name.startswith("/"), f"unsafe ZIP member: {name}")
            parts = Path(name).parts
            require(parts and parts[0] == SLUG and ".." not in parts, f"unexpected ZIP root/member: {name}")
            if info.is_dir():
                continue
            relative = Path(*parts[1:])
            target = (destination / relative).resolve()
            require(destination.resolve() in target.parents, f"ZIP traversal rejected: {name}")
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(archive.read(info))
    return destination


def changelog_notes(payload: Path, version: str) -> str:
    lines = (payload / "readme.txt").read_text(encoding="utf-8").splitlines()
    heading = f"= {version} ="
    try:
        start = lines.index(heading) + 1
    except ValueError:
        return f"{PRODUCT_NAME} {version}."
    notes: list[str] = []
    for line in lines[start:]:
        if line.startswith("= ") and line.endswith(" ="):
            break
        if line.strip():
            notes.append(line.strip())
    return "\n".join(notes) if notes else f"{PRODUCT_NAME} {version}."


def prepare_release(candidate_dir: Path, work: Path, candidate_sha: str, version: str, run_id: int) -> tuple[str, dict]:
    require(SHA_RE.fullmatch(candidate_sha) is not None, "candidate SHA must be full 40-character lowercase hex")
    require(VERSION_RE.fullmatch(version) is not None, "invalid release version")
    require(git_head(candidate_dir) == candidate_sha, "candidate checkout SHA mismatch")
    require(version_from_tree(candidate_dir) == version, "requested version does not match plugin/readme metadata")

    work.mkdir(parents=True, exist_ok=True)
    stage_a = work / "stage-a"
    stage_b = work / "stage-b"
    digest_a = stage(candidate_dir, stage_a)
    digest_b = stage(candidate_dir, stage_b)
    require(digest_a == digest_b, "staged payload is nondeterministic")
    entries_a = tree_entries(stage_a)
    require(entries_a == tree_entries(stage_b), "staged file manifest is nondeterministic")

    package_name = f"{SLUG}-{version}.zip"
    zip_a = work / "build-a" / package_name
    zip_b = work / "build-b" / package_name
    deterministic_zip(stage_a, zip_a)
    deterministic_zip(stage_b, zip_b)
    require(zip_a.read_bytes() == zip_b.read_bytes(), "release ZIP is nondeterministic")

    rc = work / "rc"
    if rc.exists():
        shutil.rmtree(rc)
    rc.mkdir(parents=True)
    shutil.copytree(stage_a, rc / "payload")
    shutil.copy2(zip_a, rc / package_name)

    manifest = {
        "schema_version": 1,
        "repository": REPOSITORY,
        "slug": SLUG,
        "candidate_sha": candidate_sha,
        "version": version,
        "preparation_run_id": int(run_id),
        "product_tree_sha256": digest_a,
        "package_name": package_name,
        "package_sha256": sha256_file(rc / package_name),
        "file_count": len(entries_a),
        "files": entries_a,
    }
    write_json(rc / "release-manifest.json", manifest)
    record = {
        "schema_version": 1,
        "state": "RC_PREPARED",
        "repository": REPOSITORY,
        "workflow": PREPARE_WORKFLOW,
        "run_id": int(run_id),
        "candidate_sha": candidate_sha,
        "version": version,
        "product_tree_sha256": digest_a,
        "package_sha256": manifest["package_sha256"],
        "external_mutation": "NONE",
    }
    write_json(rc / "preparation-record.json", record)
    return f"yci-wporg-{version}-{candidate_sha}", manifest


def load_prepared(prepared: Path, candidate_sha: str, version: str, preparation_run_id: int) -> dict:
    manifest = read_json(prepared / "release-manifest.json")
    record = read_json(prepared / "preparation-record.json")
    expected = {
        "repository": REPOSITORY,
        "slug": SLUG,
        "candidate_sha": candidate_sha,
        "version": version,
        "preparation_run_id": int(preparation_run_id),
    }
    for key, value in expected.items():
        require(manifest.get(key) == value, f"prepared manifest mismatch: {key}")
    require(record.get("state") == "RC_PREPARED", "preparation record is not terminal RC_PREPARED")
    require(record.get("run_id") == int(preparation_run_id), "preparation record run mismatch")
    require(
        record.get("candidate_sha") == candidate_sha and record.get("version") == version,
        "preparation record identity mismatch",
    )
    payload = prepared / "payload"
    require(tree_entries(payload) == manifest.get("files"), "prepared payload manifest mismatch")
    require(tree_digest(payload) == manifest.get("product_tree_sha256"), "prepared payload digest mismatch")
    package = prepared / str(manifest.get("package_name"))
    require(package.is_file(), "prepared ZIP missing")
    require(sha256_file(package) == manifest.get("package_sha256"), "prepared ZIP digest mismatch")
    with tempfile.TemporaryDirectory(prefix="yci-zip-verify-") as temporary:
        extracted = safe_extract_product(package, Path(temporary))
        require(tree_digest(extracted) == manifest.get("product_tree_sha256"), "ZIP product identity mismatch")
    return manifest


class GitHubAPI:
    def __init__(self, token: str | None = None):
        self.token = token or os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN")
        require(bool(self.token), "GitHub token is required for release authentication")
        self.base = f"https://api.github.com/repos/{REPOSITORY}"

    def _request(
        self,
        method: str,
        url: str,
        data: bytes | None = None,
        headers: dict[str, str] | None = None,
        *,
        decode_json: bool = True,
    ):
        request_headers = {
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {self.token}",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "yci-release-publisher",
        }
        if headers:
            request_headers.update(headers)
        request = urllib.request.Request(url, data=data, headers=request_headers, method=method)
        try:
            with urllib.request.urlopen(request, timeout=30) as response:
                body = response.read()
                content_type = response.headers.get("Content-Type", "")
                if decode_json and ("application/json" in content_type or body.startswith((b"{", b"["))):
                    return json.loads(body.decode("utf-8"))
                return body
        except urllib.error.HTTPError as error:
            detail = error.read().decode("utf-8", errors="replace")
            raise ReleaseError(
                f"GitHub API {method} {url} failed: HTTP {error.code}: {detail[:500]}"
            ) from None

    def get(self, path: str):
        return self._request("GET", self.base + "/" + path.lstrip("/"))

    def get_optional(self, path: str):
        url = self.base + "/" + path.lstrip("/")
        headers = {
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {self.token}",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "yci-release-publisher",
        }
        request = urllib.request.Request(url, headers=headers, method="GET")
        try:
            with urllib.request.urlopen(request, timeout=30) as response:
                return json.loads(response.read().decode("utf-8"))
        except urllib.error.HTTPError as error:
            if error.code == 404:
                return None
            detail = error.read().decode("utf-8", errors="replace")
            raise ReleaseError(f"GitHub API GET {url} failed: HTTP {error.code}: {detail[:500]}") from None

    def post(self, path: str, value):
        return self._request(
            "POST",
            self.base + "/" + path.lstrip("/"),
            canonical(value),
            {"Content-Type": "application/json"},
        )

    def ensure_preparation_run(self, run_id: int, candidate_sha: str, artifact_name: str) -> None:
        run_data = self.get(f"actions/runs/{run_id}")
        require(run_data.get("path") == PREPARE_WORKFLOW, "preparation run used the wrong workflow")
        require(run_data.get("event") == "workflow_dispatch", "preparation run was not manually dispatched")
        require(run_data.get("head_branch") == "main", "preparation run was not on main")
        require(run_data.get("head_sha") == candidate_sha, "preparation run head does not match candidate")
        require(run_data.get("run_attempt") == 1, "preparation reruns are not accepted as release authority")
        require(
            run_data.get("status") == "completed" and run_data.get("conclusion") == "success",
            "preparation run did not complete successfully",
        )
        artifacts = self.get(f"actions/runs/{run_id}/artifacts?per_page=100").get("artifacts", [])
        matching = [item for item in artifacts if item.get("name") == artifact_name and not item.get("expired")]
        require(len(matching) == 1, "expected exactly one live immutable preparation artifact")

    def ensure_candidate_on_protected_main(self, candidate_sha: str) -> str:
        branch = self.get("branches/main")
        require(branch.get("protected") is True, "main must remain protected")
        current = branch["commit"]["sha"]
        if current != candidate_sha:
            compare = self.get(f"compare/{candidate_sha}...{current}")
            require(
                compare.get("merge_base_commit", {}).get("sha") == candidate_sha,
                "prepared candidate is no longer accepted main ancestry",
            )
            require(compare.get("status") == "ahead", "prepared candidate is not an ancestor of current main")
        return current

    def tag_ref(self, version: str):
        return self.get_optional(f"git/ref/tags/{urllib.parse.quote(version, safe='')}")

    def resolve_tag_commit(self, version: str) -> str | None:
        ref = self.tag_ref(version)
        if ref is None:
            return None
        obj = ref.get("object", {})
        if obj.get("type") == "commit":
            return obj.get("sha")
        if obj.get("type") == "tag":
            tag = self.get(f"git/tags/{obj.get('sha')}")
            require(tag.get("tag") == version, "annotated release tag name mismatch")
            require(tag.get("object", {}).get("type") == "commit", "annotated release tag does not target a commit")
            return tag.get("object", {}).get("sha")
        raise ReleaseError("unsupported existing Git tag object type")

    def seal_tag(self, version: str, candidate_sha: str) -> str:
        existing_ref = self.tag_ref(version)
        if existing_ref is not None:
            require(existing_ref.get("object", {}).get("type") == "tag", "existing release tag is not annotated")
            require(self.resolve_tag_commit(version) == candidate_sha, "release Git tag points to a different commit")
            return str(existing_ref["object"]["sha"])
        tag_object = self.post(
            "git/tags",
            {
                "tag": version,
                "message": f"{PRODUCT_NAME} {version}",
                "object": candidate_sha,
                "type": "commit",
                "tagger": {
                    "name": "YoOhw Studio",
                    "email": "152001663+yoohwz@users.noreply.github.com",
                },
            },
        )
        tag_sha = str(tag_object["sha"])
        self.post("git/refs", {"ref": f"refs/tags/{version}", "sha": tag_sha})
        require(self.resolve_tag_commit(version) == candidate_sha, "annotated release Git tag verification failed")
        return tag_sha

    def _release_asset_bytes(self, asset_id: int) -> bytes:
        return self._request(
            "GET",
            self.base + f"/releases/assets/{asset_id}",
            headers={"Accept": "application/octet-stream"},
            decode_json=False,
        )

    def _upload_asset(self, release_id: int, name: str, data: bytes, content_type: str) -> None:
        url = (
            f"https://uploads.github.com/repos/{REPOSITORY}/releases/{release_id}/assets?"
            + urllib.parse.urlencode({"name": name})
        )
        self._request(
            "POST",
            url,
            data,
            {"Content-Type": content_type, "Accept": "application/vnd.github+json"},
        )

    def create_or_reconcile_release(self, manifest: dict, prepared: Path) -> int:
        version = str(manifest["version"])
        candidate_sha = str(manifest["candidate_sha"])
        require(self.resolve_tag_commit(version) == candidate_sha, "immutable release Git tag is missing")
        release = self.get_optional(f"releases/tags/{urllib.parse.quote(version, safe='')}")
        if release is None:
            release = self.post(
                "releases",
                {
                    "tag_name": version,
                    "target_commitish": candidate_sha,
                    "name": f"{PRODUCT_NAME} {version}",
                    "body": changelog_notes(prepared / "payload", version),
                    "draft": False,
                    "prerelease": False,
                },
            )
        require(release.get("draft") is False and release.get("prerelease") is False,
                "existing GitHub Release has unexpected draft/prerelease state")
        release_id = int(release["id"])
        assets = self.get(f"releases/{release_id}/assets?per_page=100")
        by_name = {item["name"]: item for item in assets}
        files = [
            (prepared / str(manifest["package_name"]), "application/zip"),
            (prepared / "release-manifest.json", "application/json"),
        ]
        for path, content_type in files:
            expected = sha256_file(path)
            if path.name in by_name:
                actual = sha256_bytes(self._release_asset_bytes(int(by_name[path.name]["id"])))
                require(actual == expected, f"existing GitHub Release asset differs: {path.name}")
            else:
                self._upload_asset(release_id, path.name, path.read_bytes(), content_type)
        return release_id


def svn_approval_identity(snapshot: dict) -> dict:
    missing = [key for key in SVN_APPROVAL_SNAPSHOT_KEYS if key not in snapshot]
    require(not missing, "WordPress.org SVN approval snapshot is incomplete: " + ", ".join(missing))
    return {key: snapshot[key] for key in SVN_APPROVAL_SNAPSHOT_KEYS}


class SVNWorkspace:
    def __init__(self, path: Path):
        self.path = path

    def checkout(self) -> "SVNWorkspace":
        if self.path.exists():
            shutil.rmtree(self.path)
        run(["svn", "checkout", "--depth", "immediates", SVN_URL, self.path, "--non-interactive"])
        for name, depth in (("trunk", "infinity"), ("tags", "immediates"), ("assets", "infinity")):
            target = self.path / name
            if target.exists():
                run(["svn", "update", "--set-depth", depth, name, "--non-interactive"], cwd=self.path)
        require(
            (self.path / "trunk").is_dir() and (self.path / "tags").is_dir(),
            "WordPress.org SVN layout is incomplete",
        )
        return self

    def _revision(self, relative: str) -> str | None:
        target = self.path / relative
        if not target.exists():
            return None
        return run(["svn", "info", "--show-item", "last-changed-revision", relative], cwd=self.path).stdout.strip()

    def snapshot(self, version: str) -> dict:
        assets = self.path / "assets"
        return {
            "trunk_revision": self._revision("trunk"),
            "trunk_tree_sha256": tree_digest(self.path / "trunk"),
            "assets_revision": self._revision("assets"),
            "assets_tree_sha256": tree_digest(assets) if assets.is_dir() else None,
            "target_tag_exists": (self.path / "tags" / version).exists(),
        }

    def compare_snapshot(self, expected: dict, version: str) -> dict:
        current = self.snapshot(version)
        require(
            svn_approval_identity(current) == svn_approval_identity(expected),
            "WordPress.org SVN changed after approved preflight",
        )
        return current

    def _status(self) -> list[str]:
        return [line for line in run(["svn", "status"], cwd=self.path).stdout.splitlines() if line.strip()]

    def stage(self, payload: Path, version: str, expected_snapshot: dict | None = None) -> dict:
        if expected_snapshot is not None:
            self.compare_snapshot(expected_snapshot, version)
        before = self.snapshot(version)
        require(before["target_tag_exists"] is False, f"WordPress.org tag {version} already exists")
        run(
            [
                "rsync",
                "-a",
                "--delete",
                "--exclude",
                ".svn/",
                f"{payload}/",
                f"{self.path / 'trunk'}/",
            ]
        )
        for line in self._status():
            if line.startswith("!"):
                missing = line[8:].strip()
                run(["svn", "rm", "--force", missing], cwd=self.path)
        run(["svn", "add", "--force", "trunk"], cwd=self.path)
        run(["svn", "copy", "trunk", f"tags/{version}"], cwd=self.path)
        status = self._status()
        require(status, "publication staging produced no SVN delta")
        paths: list[str] = []
        for line in status:
            require(line[0] not in {"C", "~"}, f"SVN conflict/obstruction: {line}")
            relative = line[8:].strip()
            paths.append(relative)
            require(
                relative == "trunk"
                or relative.startswith("trunk/")
                or relative == f"tags/{version}"
                or relative.startswith(f"tags/{version}/"),
                f"publication attempted to mutate forbidden SVN path: {relative}",
            )
            require(
                not (relative == "assets" or relative.startswith("assets/")),
                "WordPress.org assets are immutable in normal publication",
            )
        has_trunk_delta = any(path == "trunk" or path.startswith("trunk/") for path in paths)
        has_target_tag_delta = any(
            path == f"tags/{version}" or path.startswith(f"tags/{version}/") for path in paths
        )
        require(
            has_trunk_delta,
            "tag-only release is unsupported; publication requires a trunk delta before mutation",
        )
        require(has_target_tag_delta, "publication staging did not create the target tag")
        return {"before": before, "changed_paths": sorted(paths)}

    def atomic_commit(
        self,
        version: str,
        candidate_sha: str,
        run_id: int,
        username: str,
        password: str,
        attempt_path: Path,
    ) -> int:
        require(username == EXPECTED_SVN_AUTHOR, "unexpected WordPress.org SVN username")
        require(password != "", "WordPress.org SVN password is required")
        message = f"Release {SLUG} {version} from {candidate_sha} (GitHub run {run_id})"
        attempt = {
            "schema_version": 1,
            "state": "SVN_COMMIT_ATTEMPTED",
            "version": version,
            "candidate_sha": candidate_sha,
            "publish_run_id": int(run_id),
            "message": message,
            "outcome": "UNKNOWN",
        }
        write_json(attempt_path, attempt)
        result = run(
            [
                "svn",
                "commit",
                "trunk",
                f"tags/{version}",
                "--username",
                username,
                "--password-from-stdin",
                "--non-interactive",
                "--no-auth-cache",
                "-m",
                message,
            ],
            cwd=self.path,
            input_text=password + "\n",
            remove_env={"WPORG_SVN_PASSWORD"},
            check=False,
        )
        if result.returncode != 0:
            write_json(attempt_path, {**attempt, "returncode": result.returncode})
            raise ReleaseError(
                "SVN commit outcome is unknown; do not retry publication, use verify-only recovery"
            )
        match = re.search(r"Committed revision\s+([0-9]+)\.", result.stdout)
        require(match is not None, "SVN commit succeeded without a parseable committed revision")
        revision = int(match.group(1))
        write_json(attempt_path, {**attempt, "outcome": "COMMITTED", "revision": revision})
        return revision


def plugin_relative_svn_path(path: str) -> str:
    prefix = f"/{SLUG}/"
    require(path.startswith(prefix), f"SVN release revision changed path outside {SLUG}: {path}")
    relative = "/" + path[len(prefix):].lstrip("/")
    require(relative != "/", f"SVN release revision changed plugin root unexpectedly: {path}")
    return relative


def svn_publication_log(version: str, candidate_sha: str, run_id: int) -> dict:
    message = f"Release {SLUG} {version} from {candidate_sha} (GitHub run {run_id})"
    raw = run(
        ["svn", "log", "--xml", "-v", "--search", message, SVN_URL, "--non-interactive"]
    ).stdout
    root = ET.fromstring(raw)
    matches = []
    for entry in root.findall("logentry"):
        if (entry.findtext("msg") or "") != message:
            continue
        raw_paths = sorted(item.text or "" for item in entry.findall("./paths/path"))
        matches.append(
            {
                "revision": int(entry.attrib["revision"]),
                "author": entry.findtext("author") or "",
                "message": message,
                "changed_paths": raw_paths,
            }
        )
    require(len(matches) == 1, "could not authenticate exactly one WordPress.org SVN publication revision")
    result = matches[0]
    require(result["author"] == EXPECTED_SVN_AUTHOR, "WordPress.org release was authored by an unexpected committer")

    plugin_paths = sorted(plugin_relative_svn_path(path) for path in result["changed_paths"])
    tag_prefix = f"/tags/{version}"
    for path in plugin_paths:
        require(
            not (path == "/assets" or path.startswith("/assets/")),
            "SVN release revision changed assets",
        )
        require(
            path == "/trunk"
            or path.startswith("/trunk/")
            or path == tag_prefix
            or path.startswith(tag_prefix + "/"),
            f"SVN release revision changed unexpected plugin path: {path}",
        )
    require(
        any(path == "/trunk" or path.startswith("/trunk/") for path in plugin_paths),
        "SVN release revision did not change trunk",
    )
    require(
        any(path == tag_prefix or path.startswith(tag_prefix + "/") for path in plugin_paths),
        "SVN release revision did not create target tag",
    )
    result["plugin_relative_changed_paths"] = plugin_paths
    return result


def verify_svn(manifest: dict, publish_run_id: int, work: Path) -> dict:
    version = str(manifest["version"])
    repo = SVNWorkspace(work / "svn-verify").checkout()
    tag = repo.path / "tags" / version
    require(tag.exists(), f"WordPress.org tag {version} is missing")
    run(["svn", "update", "--set-depth", "infinity", f"tags/{version}", "--non-interactive"], cwd=repo.path)
    expected = str(manifest["product_tree_sha256"])
    require(tree_digest(repo.path / "trunk") == expected, "WordPress.org trunk product identity mismatch")
    require(tree_digest(tag) == expected, "WordPress.org release tag product identity mismatch")
    log = svn_publication_log(version, str(manifest["candidate_sha"]), publish_run_id)
    return {
        "svn_revision": log["revision"],
        "svn_author": log["author"],
        "svn_changed_paths": log["changed_paths"],
        "svn_plugin_relative_changed_paths": log["plugin_relative_changed_paths"],
        "trunk_tree_sha256": expected,
        "tag_tree_sha256": expected,
    }


def fetch_public_package(version: str, destination: Path) -> bool:
    url = f"https://downloads.wordpress.org/plugin/{SLUG}.{version}.zip"
    request = urllib.request.Request(url, headers={"User-Agent": "yci-release-verifier"})
    try:
        with urllib.request.urlopen(request, timeout=45) as response:
            if response.status != 200:
                return False
            destination.write_bytes(response.read())
            return True
    except urllib.error.HTTPError as error:
        if error.code in {404, 429, 500, 502, 503, 504}:
            return False
        raise ReleaseError(f"WordPress.org public download failed: HTTP {error.code}") from None
    except urllib.error.URLError:
        return False


def verify_publication(manifest: dict, publish_run_id: int, work: Path) -> dict:
    svn = verify_svn(manifest, publish_run_id, work)
    public_zip = work / f"public-{manifest['version']}.zip"
    if not fetch_public_package(str(manifest["version"]), public_zip):
        return {
            "schema_version": 1,
            "state": "WPORG_PROPAGATION_PENDING",
            "identity": {
                "candidate_sha": manifest["candidate_sha"],
                "version": manifest["version"],
                "product_tree_sha256": manifest["product_tree_sha256"],
            },
            **svn,
        }
    with tempfile.TemporaryDirectory(prefix="yci-public-") as temporary:
        extracted = safe_extract_product(public_zip, Path(temporary))
        require(
            tree_digest(extracted) == manifest["product_tree_sha256"],
            "public WordPress.org package differs from prepared product",
        )
    return {
        "schema_version": 1,
        "state": "WPORG_PUBLIC_RELEASE_VERIFIED",
        "identity": {
            "candidate_sha": manifest["candidate_sha"],
            "version": manifest["version"],
            "product_tree_sha256": manifest["product_tree_sha256"],
            "public_package_sha256": sha256_file(public_zip),
        },
        **svn,
    }
