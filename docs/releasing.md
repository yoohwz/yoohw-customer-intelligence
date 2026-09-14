# Customer Intelligence — WordPress.org release

This is a release-only control plane. It does not replace normal YCI PR review,
`YCI Required CI`, independent review for CONTROLLED work, or a separate Human merge
or release decision. Merging release tooling creates capability only; it does not
publish any version automatically.

The design mirrors the guarded Support Portal publisher: protected-main control,
immutable Prepare, dry-run-first SVN publication, a Human-gated production
Environment, one atomic SVN mutation attempt, read-only recovery, and GitHub Release
creation only after public WordPress.org verification.

## Trust model

- Both release workflows are manual `workflow_dispatch` workflows and execute only
  from protected `main`.
- The release control plane is checked out from the workflow's exact main SHA.
- A prepared candidate is checked out separately and treated as release data.
- Before staging, the candidate staging helper with the trusted control-plane helper
  replaces the candidate copy of `scripts/stage-distribution.sh`; candidate release
  scripts are therefore not trusted execution authority.
- `scripts/stage-distribution.sh` is the canonical product-payload definition.
- Prepare stages the product twice and requires the staged trees and deterministic
  ZIP bytes to match.
- WordPress Plugin Check runs against the exact prepared `rc/payload`.
- The preparation artifact binds repository, candidate SHA, version, product tree
  SHA-256, per-file SHA-256 inventory, package name and package SHA-256.
- Publication authenticates the successful Prepare run and artifact, reproduces the
  candidate staged tree, and verifies the candidate remains accepted protected-main
  ancestry before reading WordPress.org SVN.
- Normal publication can mutate only `trunk` and `tags/<version>`; WordPress.org
  `assets/` is immutable in this workflow.
- `WPORG_SVN_PASSWORD` is available only to the single production SVN commit step.
  It is sent to SVN on stdin, removed from the child-process environment, and never
  placed on a command-line argument.
- Dry-run, preflight and verify-only do not receive the SVN password.

## One-time owner setup

A repository administrator and WordPress.org plugin committer must configure:

1. GitHub Environment `wordpress-org-production`.
   - Require Human reviewer approval.
   - Prevent self-review where available.
   - Restrict deployment branches to protected `main`.
2. In that Environment, add variable `WPORG_SVN_USERNAME` with the exact value `yoohw`.
3. In that Environment, add secret `WPORG_SVN_PASSWORD` using the WordPress.org
   **SVN-specific password** for `yoohw`. Enter it directly in GitHub; never paste it
   into ChatGPT, Issues, PR comments, workflow inputs, artifacts or logs.
4. Protect numeric release tags such as `1.3.1` from update, force-update and deletion.
   Production publication must be able to create a new **annotated** tag, but an
   existing release tag must remain immutable.
5. Keep the current `main` ruleset and exact required `YCI Required CI` check. Do not
   weaken protection or add bypasses to make publication pass.

The workflows intentionally fail closed if Environment configuration is missing or
incorrect.

## Before Prepare

The intended release candidate must already be merged on protected `main` and carry
one exact numeric release version in all three places:

- `Version:` in `yoohw-customer-intelligence.php`;
- `YOOHW_COS_VERSION` in `yoohw-customer-intelligence.php`;
- `Stable tag:` in `readme.txt`.

The release tooling never changes version metadata. Product/version changes must
complete their normal task lifecycle before release execution.

## Step 1 — Prepare immutable candidate

Run **Prepare Customer Intelligence WordPress.org Release Candidate** on `main` with:

- `candidate_sha`: exact current protected-main SHA, full 40 characters;
- `version`: exact numeric version matching all three metadata locations.

Prepare fails closed unless the candidate equals the workflow's exact protected-main
SHA. It checks out trusted release control and candidate data separately, replaces the
candidate staging helper, validates version identity, stages twice, builds two
byte-identical deterministic ZIPs, runs WordPress Plugin Check against the exact
payload, and uploads one artifact named:

`yci-wporg-<version>-<candidate_sha>`

The artifact contains `payload/`, `yoohw-customer-intelligence-<version>.zip`,
`release-manifest.json`, and `preparation-record.json`. Keep the successful Prepare
run ID; a failed or superseded Prepare is not publication authority.

## Step 2 — Dry-run publication

Run **Publish Customer Intelligence to WordPress.org** with:

- `operation=publish`;
- successful `preparation_run_id`;
- the same `candidate_sha` and `version`;
- `dry_run=true`.

This path receives no SVN credential. It authenticates the prepared artifact and
candidate, reads WordPress.org SVN, proves target-tag absence, snapshots plugin-scoped
trunk/assets state, performs a second fresh checkout/recheck, and stages the exact
`trunk` + `tags/<version>` delta locally only. Review the preflight before authorizing
production.

## Step 3 — Production publication

After separate explicit Human release authority, dispatch a new Publish run with the
same preparation/candidate/version and `dry_run=false`.

The read-only preflight runs first. The production job then waits on
`wordpress-org-production`. After Environment approval it reauthenticates the
prepared artifact and candidate, verifies the approved SVN snapshot is still current,
restages the exact payload, validates `WPORG_SVN_USERNAME`, creates or verifies the
immutable annotated Git tag, and attempts one atomic SVN commit for `trunk` plus the
new target tag.

After commit, the workflow authenticates exactly one matching SVN revision authored by
`yoohw`, rejects any changed path outside the plugin's trunk/target tag, verifies trunk
and tag product identity, and checks the public versioned download at WordPress.org.

If SVN is correct but the public package has not propagated, the state is
`WPORG_PROPAGATION_PENDING`. Do not recommit. Once the public package matches the
prepared product, the state becomes `WPORG_PUBLIC_RELEASE_VERIFIED`.

Only `WPORG_PUBLIC_RELEASE_VERIFIED` can reach the separate Environment-gated GitHub
Release job, which re-verifies public identity and creates or reconciles the matching
GitHub Release assets.

## Verify-only and recovery

Use `operation=verify-only` with the original Prepare identity and
`original_publish_run_id` when a production run may already have mutated SVN or public
propagation is incomplete. Use `dry_run=true` for read-only recovery. Use
`dry_run=false` only when successful public verification should also permit the
separately Environment-gated GitHub Release job.

Verify-only never receives the SVN password and never commits SVN. It authenticates
the original production run/preflight and the immutable annotated tag, then rebuilds
release state from fresh SVN and public-download evidence.

If the commit command reports `SVN commit outcome is unknown`, do not retry the
production commit. Use verify-only first. The same recovery path applies after a
post-commit verification failure or `WPORG_PROPAGATION_PENDING`.

## Expected terminal states

- `RC_PREPARED` — immutable release candidate prepared; no external mutation.
- `READ_ONLY_PUBLICATION_PREFLIGHT` — exact approval target captured.
- `FINAL_PRE_MUTATION_REMOTE_RECHECK` — approved SVN state is still current.
- `TAG_SEALED` — immutable annotated numeric Git tag points to the candidate.
- `SVN_ATOMIC_COMMIT_RECORDED` — one SVN commit response reported a revision.
- `WPORG_PROPAGATION_PENDING` — SVN identity is correct; public ZIP is not ready.
- `WPORG_PUBLIC_RELEASE_VERIFIED` — SVN and public WordPress.org package match.
- `GITHUB_RELEASE_VERIFIED` — the matching GitHub Release/assets exist and remain
  bound to the verified public release.

## Failure rules

- Existing `tags/<version>` at preflight: stop; never overwrite it.
- SVN trunk/assets/tag-absence identity changes after preflight: stop and dispatch a
  fresh run so Human approval targets the new state.
- Prepared artifact, candidate, tree or package identity mismatch: stop and Prepare
  again through the normal accepted flow.
- Missing/mismatched production Environment configuration: stop before mutation.
- Any attempted `assets/` mutation: stop.
- Ambiguous SVN commit outcome: do not recommit; use verify-only.
- Public package differs from the prepared product: stop and do not create a GitHub
  Release.
- Existing Git tag or GitHub Release asset has conflicting identity: stop.

## Release boundary

These workflows execute a separately authorized release. They do not trigger on merge,
tag push or PR comments, and they do not parse approval prose. Merging this publisher
does not grant release authority and does not publish any version automatically.
