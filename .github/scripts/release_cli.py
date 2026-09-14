#!/usr/bin/env python3
"""Narrow workflow entry points for the YCI WordPress.org publisher."""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import sys

import release_lib as rel


def settings() -> tuple[str, str, int, Path, Path, Path]:
    candidate = os.environ.get("CANDIDATE_SHA", "")
    version = os.environ.get("VERSION", "")
    preparation_run_id = int(os.environ.get("PREPARATION_RUN_ID", "0") or "0")
    rel.require(rel.SHA_RE.fullmatch(candidate) is not None, "invalid candidate SHA")
    rel.require(rel.VERSION_RE.fullmatch(version) is not None, "invalid release version")
    work = Path(os.environ["RUNNER_TEMP"]) / "yci-release"
    work.mkdir(parents=True, exist_ok=True)
    candidate_dir = Path(os.environ.get("CANDIDATE_DIR", Path.cwd() / "candidate-data")).resolve()
    prepared = Path(os.environ.get("PREPARED_DIR", Path.cwd() / "prepared")).resolve()
    return candidate, version, preparation_run_id, work, candidate_dir, prepared


def say(state: str, **evidence) -> None:
    value = {"state": state, **evidence}
    text = json.dumps(value, sort_keys=True, indent=2) + "\n"
    print(text, end="")
    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a", encoding="utf-8") as handle:
            handle.write(f"## {state}\n\n```json\n{text}```\n")
    output = os.environ.get("GITHUB_OUTPUT")
    if output:
        with open(output, "a", encoding="utf-8") as handle:
            handle.write(f"state={state}\n")


def load_current() -> tuple[dict, Path, str, str, int, Path]:
    candidate, version, preparation_run_id, work, _, prepared = settings()
    rel.require(preparation_run_id > 0, "preparation run ID is required")
    manifest = rel.load_prepared(prepared, candidate, version, preparation_run_id)
    return manifest, prepared, candidate, version, preparation_run_id, work


def prepare() -> None:
    candidate, version, _, work, candidate_dir, _ = settings()
    rel.require(
        candidate == os.environ.get("GITHUB_SHA"),
        "Prepare may only package the exact protected-main workflow SHA",
    )
    rel.require(os.environ.get("GITHUB_REF") == "refs/heads/main", "Prepare must run from main")
    rel.require(os.environ.get("GITHUB_REF_PROTECTED") == "true", "Prepare requires protected main")
    artifact, manifest = rel.prepare_release(
        candidate_dir,
        work,
        candidate,
        version,
        int(os.environ["GITHUB_RUN_ID"]),
    )
    output = os.environ.get("GITHUB_OUTPUT")
    if output:
        with open(output, "a", encoding="utf-8") as handle:
            handle.write(f"artifact_name={artifact}\n")
            handle.write(f"product_tree_sha256={manifest['product_tree_sha256']}\n")
            handle.write(f"package_sha256={manifest['package_sha256']}\n")
    say(
        "RC_PREPARED",
        candidate_sha=candidate,
        version=version,
        product_tree_sha256=manifest["product_tree_sha256"],
        package_sha256=manifest["package_sha256"],
        file_count=manifest["file_count"],
        EXTERNAL_MUTATION="NONE",
    )


def context() -> None:
    manifest, _, candidate, version, preparation_run_id, work = load_current()
    operation = os.environ.get("OPERATION", "")
    dry_run = os.environ.get("DRY_RUN", "")
    rel.require(operation in {"publish", "verify-only"}, "invalid publication operation")
    rel.require(dry_run in {"true", "false"}, "invalid dry-run flag")
    rel.require(os.environ.get("GITHUB_REF") == "refs/heads/main", "publisher must run from main")
    rel.require(os.environ.get("GITHUB_REF_PROTECTED") == "true", "publisher requires protected main")
    rel.require(os.environ.get("GITHUB_RUN_ATTEMPT") == "1", "publisher workflow reruns are not accepted")

    api = rel.GitHubAPI()
    artifact = f"yci-wporg-{version}-{candidate}"
    api.ensure_preparation_run(preparation_run_id, candidate, artifact)
    current_main = api.ensure_candidate_on_protected_main(candidate)

    candidate_dir = Path(os.environ["CANDIDATE_DIR"]).resolve()
    rel.require(rel.git_head(candidate_dir) == candidate, "candidate checkout SHA mismatch")
    candidate_stage = work / "candidate-stage"
    digest = rel.stage(candidate_dir, candidate_stage)
    rel.require(
        digest == manifest["product_tree_sha256"],
        "candidate product tree differs from prepared release",
    )
    say(
        "PUBLISHER_CONTEXT_AUTHENTICATED",
        candidate_sha=candidate,
        current_main_sha=current_main,
        version=version,
        preparation_run_id=preparation_run_id,
        product_tree_sha256=digest,
        EXTERNAL_MUTATION="NONE",
    )


def preflight() -> None:
    manifest, prepared, candidate, version, preparation_run_id, work = load_current()
    rel.require(os.environ.get("OPERATION") == "publish", "preflight is publish-only")
    repo = rel.SVNWorkspace(work / "svn-preflight").checkout()
    snapshot = repo.snapshot(version)
    rel.require(snapshot["target_tag_exists"] is False, f"WordPress.org tag {version} already exists")
    staged = repo.stage(prepared / "payload", version)
    record = {
        "schema_version": 1,
        "state": "READ_ONLY_PUBLICATION_PREFLIGHT",
        "repository": rel.REPOSITORY,
        "candidate_sha": candidate,
        "version": version,
        "preparation_run_id": preparation_run_id,
        "publish_run_id": int(os.environ["GITHUB_RUN_ID"]),
        "dry_run": os.environ.get("DRY_RUN") == "true",
        "product_tree_sha256": manifest["product_tree_sha256"],
        "snapshot": snapshot,
        "staged_changed_paths": staged["changed_paths"],
        "external_mutation": "NONE",
    }
    rel.write_json(work / "preflight-record.json", record)
    say(record["state"], record=record, EXTERNAL_MUTATION="NONE")


def approved_preflight(manifest: dict, candidate: str, version: str, preparation_run_id: int) -> dict:
    directory = Path(os.environ["PREFLIGHT_DIR"]).resolve()
    record = rel.read_json(directory / "preflight-record.json")
    expected = {
        "state": "READ_ONLY_PUBLICATION_PREFLIGHT",
        "repository": rel.REPOSITORY,
        "candidate_sha": candidate,
        "version": version,
        "preparation_run_id": preparation_run_id,
        "product_tree_sha256": manifest["product_tree_sha256"],
    }
    for key, value in expected.items():
        rel.require(record.get(key) == value, f"preflight evidence mismatch: {key}")
    rel.require(
        record.get("snapshot", {}).get("target_tag_exists") is False,
        "approved preflight did not prove target tag absence",
    )
    return record


def recheck() -> None:
    manifest, prepared, candidate, version, preparation_run_id, work = load_current()
    approved = approved_preflight(manifest, candidate, version, preparation_run_id)
    rel.require(
        approved["dry_run"] == (os.environ.get("DRY_RUN") == "true"),
        "publication intent changed after preflight",
    )
    repo = rel.SVNWorkspace(work / "svn-final").checkout()
    staged = repo.stage(prepared / "payload", version, approved["snapshot"])
    record = {
        "schema_version": 1,
        "state": "FINAL_PRE_MUTATION_REMOTE_RECHECK",
        "candidate_sha": candidate,
        "version": version,
        "preparation_run_id": preparation_run_id,
        "preflight_publish_run_id": approved["publish_run_id"],
        "product_tree_sha256": manifest["product_tree_sha256"],
        "snapshot": approved["snapshot"],
        "staged_changed_paths": staged["changed_paths"],
        "external_mutation": "NONE",
    }
    rel.write_json(work / "final-record.json", record)
    say(
        record["state"],
        identity={"candidate_sha": candidate, "version": version},
        EXTERNAL_MUTATION="NONE",
    )


def mutation_guard(
    manifest: dict,
    candidate: str,
    version: str,
    preparation_run_id: int,
    work: Path,
) -> None:
    rel.require(os.environ.get("OPERATION") == "publish", "mutation is publish-only")
    rel.require(os.environ.get("DRY_RUN") == "false", "dry-run cannot mutate external state")
    rel.require(
        os.environ.get("PUBLISH_ENVIRONMENT") == "wordpress-org-production",
        "production Environment boundary is required",
    )
    record = rel.read_json(work / "final-record.json")
    rel.require(
        record.get("state") == "FINAL_PRE_MUTATION_REMOTE_RECHECK",
        "final remote recheck evidence missing",
    )
    rel.require(
        record.get("candidate_sha") == candidate and record.get("version") == version,
        "final remote recheck identity mismatch",
    )
    rel.require(
        record.get("preparation_run_id") == preparation_run_id,
        "final remote recheck preparation mismatch",
    )
    rel.require(
        record.get("product_tree_sha256") == manifest["product_tree_sha256"],
        "final remote recheck product mismatch",
    )


def seal() -> None:
    manifest, _, candidate, version, preparation_run_id, work = load_current()
    mutation_guard(manifest, candidate, version, preparation_run_id, work)
    tag_object_sha = rel.GitHubAPI().seal_tag(version, candidate)
    rel.write_json(
        work / "tag-record.json",
        {
            "state": "TAG_SEALED",
            "candidate_sha": candidate,
            "version": version,
            "tag_object_sha": tag_object_sha,
        },
    )
    say(
        "TAG_SEALED",
        candidate_sha=candidate,
        version=version,
        tag_object_sha=tag_object_sha,
    )


def commit() -> None:
    manifest, _, candidate, version, preparation_run_id, work = load_current()
    mutation_guard(manifest, candidate, version, preparation_run_id, work)
    tag = rel.read_json(work / "tag-record.json")
    rel.require(
        tag.get("state") == "TAG_SEALED"
        and tag.get("candidate_sha") == candidate
        and tag.get("version") == version
        and re.fullmatch(r"[0-9a-f]{40}", str(tag.get("tag_object_sha", ""))) is not None,
        "annotated release Git tag seal missing",
    )
    api = rel.GitHubAPI()
    rel.require(api.resolve_tag_commit(version) == candidate, "release Git tag changed before SVN commit")

    username = os.environ.get("WPORG_SVN_USERNAME", "")
    password = os.environ.get("WPORG_SVN_PASSWORD", "")
    repo = rel.SVNWorkspace(work / "svn-final")
    rel.require(repo.path.is_dir(), "final staged SVN working copy is missing")
    revision = repo.atomic_commit(
        version,
        candidate,
        int(os.environ["GITHUB_RUN_ID"]),
        username,
        password,
        work / "commit-attempt.json",
    )
    say(
        "SVN_ATOMIC_COMMIT_RECORDED",
        revision=revision,
        candidate_sha=candidate,
        version=version,
    )


def verify_for_run(publish_run_id: int) -> dict:
    manifest, _, candidate, version, _, work = load_current()
    rel.require(
        rel.GitHubAPI().resolve_tag_commit(version) == candidate,
        "release Git tag is missing or changed",
    )
    record = rel.verify_publication(manifest, publish_run_id, work)
    rel.write_json(
        work / "publication-record.json",
        {
            **record,
            "repository": rel.REPOSITORY,
            "preparation_run_id": int(os.environ["PREPARATION_RUN_ID"]),
            "publish_run_id": int(publish_run_id),
        },
    )
    say(record["state"], record=record)
    return record


def verify() -> None:
    verify_for_run(int(os.environ["GITHUB_RUN_ID"]))


def authenticate_original_publish(
    original_run_id: int,
    manifest: dict,
    candidate: str,
    version: str,
    preparation_run_id: int,
) -> dict:
    api = rel.GitHubAPI()
    run_data = api.get(f"actions/runs/{original_run_id}")
    rel.require(
        run_data.get("path") == rel.PUBLISH_WORKFLOW,
        "original publication used the wrong workflow",
    )
    rel.require(
        run_data.get("event") == "workflow_dispatch",
        "original publication was not manually dispatched",
    )
    rel.require(
        run_data.get("head_branch") == "main" and run_data.get("run_attempt") == 1,
        "original publication did not use accepted main attempt 1",
    )
    inputs = run_data.get("inputs") or {}
    if inputs:
        rel.require(inputs.get("operation") == "publish", "original run was not a publish operation")
        rel.require(str(inputs.get("dry_run")).lower() == "false", "original run was not production publication")
        rel.require(str(inputs.get("preparation_run_id")) == str(preparation_run_id), "original preparation input mismatch")
        rel.require(inputs.get("candidate_sha") == candidate, "original candidate input mismatch")
        rel.require(inputs.get("version") == version, "original version input mismatch")
    preflight = approved_preflight(manifest, candidate, version, preparation_run_id)
    rel.require(
        preflight.get("publish_run_id") == original_run_id,
        "original preflight run identity mismatch",
    )
    rel.require(
        preflight.get("dry_run") is False,
        "dry-run publication cannot be recovered as production",
    )
    return run_data


def recover() -> None:
    manifest, _, candidate, version, preparation_run_id, _ = load_current()
    raw = os.environ.get("ORIGINAL_PUBLISH_RUN_ID", "")
    rel.require(
        re.fullmatch(r"[1-9][0-9]*", raw) is not None,
        "original publish run ID is required for verify-only",
    )
    original = int(raw)
    authenticate_original_publish(
        original,
        manifest,
        candidate,
        version,
        preparation_run_id,
    )
    verify_for_run(original)


def release() -> None:
    manifest, prepared, candidate, version, _, work = load_current()
    if os.environ.get("OPERATION") == "verify-only":
        raw = os.environ.get("ORIGINAL_PUBLISH_RUN_ID", "")
        rel.require(
            re.fullmatch(r"[1-9][0-9]*", raw) is not None,
            "original publish run ID required",
        )
        publish_run_id = int(raw)
    else:
        publish_run_id = int(os.environ["GITHUB_RUN_ID"])
    record = rel.verify_publication(manifest, publish_run_id, work)
    rel.require(
        record["state"] == "WPORG_PUBLIC_RELEASE_VERIFIED",
        "public WordPress.org release is not yet verified",
    )
    release_id = rel.GitHubAPI().create_or_reconcile_release(manifest, prepared)
    say(
        "GITHUB_RELEASE_VERIFIED",
        release_id=release_id,
        candidate_sha=candidate,
        version=version,
    )


def main() -> int:
    commands = {
        "prepare": prepare,
        "context": context,
        "preflight": preflight,
        "recheck": recheck,
        "seal": seal,
        "commit": commit,
        "verify": verify,
        "recover": recover,
        "release": release,
    }
    if len(sys.argv) != 2 or sys.argv[1] not in commands:
        print("usage: release_cli.py " + "|".join(sorted(commands)), file=sys.stderr)
        return 2
    try:
        commands[sys.argv[1]]()
        return 0
    except rel.ReleaseError as error:
        print(f"release-error: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
