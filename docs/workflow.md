# YCI Lean Delivery

The approved Issue defines the outcome and boundary. Codex owns discovery, concise
technical design, implementation, validation, PR upkeep and bounded corrections.
ChatGPT owns framing, unresolved boundary decisions, Plan Review when required and
external Acceptance Review. Human owns merge and release authority.
Tool capability never transfers another role's authority.

## Start and recover

Fetch origin; inspect local changes and existing Issue/branch/PR state before creating
anything. Use one Issue, one admitted branch and one PR to `main`. If main moved,
inspect the delta; conflicting or material overlap needs a boundary decision. Never
overwrite unrelated work, push directly to main, force-push, reset/clean destructively
or delete unrelated branches.

No task document, allocator, state JSON, ledger, identity ref, digest chain or approval
parser exists. After compaction/resume, recover current Issue, PR, head/base, CI, reviews,
correction count and next owner from GitHub, not remembered success.

## Operator commands and navigation

GitHub Issue `#N` is the canonical task identity `CIT-N`; roadmap labels such as
`CIT-A06` remain product/roadmap identifiers and do not replace Issue identity.

- `Create ...` — ChatGPT creates the minimum useful Issue boundary and risk.
- `Run CIT-N` — Codex recovers Issue/branch/PR/head/base/current evidence and performs
  the next implementation-owned step. For Controlled work, the same run should delegate
  the required fresh reviewer after publishing the exact candidate when native
  per-role delegation is available.
- `Continue CIT-N` — recover GitHub state and resume the same task. This is a Human
  convenience alias for continued execution, not lifecycle state.
- `Plan Review CIT-N` — ChatGPT resolves a genuinely unresolved product/architecture/
  data/permission/compatibility boundary and returns execution to `Run CIT-N`.
- `Review CIT-N` — manual/standalone fresh Technical Review fallback. It never grants
  merge authority.
- `Acceptance Review CIT-N` — ChatGPT evaluates the frozen exact candidate against
  current scope, CI and required Technical Review, then records an exact-head verdict
  in GitHub.
- `Merge CIT-N` — Human authorizes merge of the unchanged accepted candidate.
- `Finalize CIT-N` — shortcut that conditionally authorizes Acceptance plus squash
  merge in one turn when every prerequisite is already current.
- `Chốt PR #N` remains a compatibility alias for Finalize when the exact PR candidate
  is already unambiguous to the Human.

Legacy `Chạy` / `Tiếp tục` may continue one unambiguous active task, but explicit
Issue-based commands are preferred for durable handoffs.

Every terminal or handoff Human-facing response should end with:

`STATUS: <navigation label>`
`Task: CIT-N`
`Next: <one exact short Human command, or None>`

Useful labels are navigation prose only, not machine state:
`READY_TO_RUN`, `IN_PROGRESS`, `PLAN_REVIEW_REQUIRED`,
`TECHNICAL_REVIEW_REQUIRED`, `TECHNICAL_REVIEW_BLOCKED`,
`ACCEPTANCE_REVIEW_REQUIRED`, `READY_TO_MERGE`,
`HUMAN_DECISION_REQUIRED`, `FINALIZED`.

## Risk and execution

- **Fast:** bounded, understood work without sensitive behavior or safety-control
  semantics. One implementer and relevant checks normally suffice. No independent
  reviewer ceremony by default.
- **Controlled:** sensitive data, permissions, security/privacy, persistence,
  compatibility-sensitive contracts, release safety or equivalent material controls.
  Require one fresh independent Technical Reviewer for every exact candidate that
  reaches review.

Separate Plan Review is required only when discovery exposes a genuinely unresolved
product, architecture, data, permission or compatibility boundary. A fully bounded
Controlled bug/fix may implement directly without a Plan Review.

If Fast discovery reveals a Controlled trigger, stop before the sensitive change,
keep the same Issue/PR, escalate risk and continue under the Controlled rules.

## Compute policy

Compute follows phase rather than mutable task metadata:

- Root / main orchestration: GPT-6 Sol / MEDIUM.
- Fast implementation: GPT-6 Sol / MEDIUM.
- Controlled deterministic implementation and correction after the boundary is settled:
  GPT-6 Sol / MEDIUM.
- Controlled discovery/architecture when a separate Plan Review is actually needed:
  GPT-6 Sol / HIGH.
- Independent Technical Review, including every fresh re-review: GPT-6 Sol / HIGH.
- GPT-6 Sol / XHIGH is exceptional for a specific unresolved architecture/security
  problem and requires an explicit reason.
- GPT-6 Astra requires explicit exceptional manual escalation and is not the governed
  default.

Reduce irrelevant context before increasing compute. Do not serialize model, effort or
execution history into the Issue merely because a phase used them, and do not change
models for individual shell/Git/lint/test commands inside a phase.

## Discovery and Plan Review

Discovery normally happens inside `Run CIT-N`. If the implementation boundary is
already clear, proceed without a separate ceremony.

When a material boundary is unresolved, discovery uses the Controlled discovery compute,
does not broaden writes, and returns:

`STATUS: PLAN_REVIEW_REQUIRED`
`Task: CIT-N`
`Next: Plan Review CIT-N`

ChatGPT Plan Review resolves only the product/scope/architecture boundary that blocks
safe execution. It should not prescribe unnecessary implementation mechanics. After
approval, return:

`STATUS: READY_TO_RUN`
`Task: CIT-N`
`Next: Run CIT-N`

## Candidate and independent review

Commit the complete change, push the admitted branch and create/update its PR. Keep one
concise PR evidence record: Issue/scope/risk, changed files, exact head/base,
commands/results, native CI/review links, limitations and next owner/action.

Historical or inferred evidence is not current-head PASS. Do not create handoff-only
source commits. The native aggregate is `YCI Required CI`; unexpected skips are failures.
Require PRs and that check on main, preserve stronger rules and prevent force-push and
deletion.

For a Controlled candidate, after the exact candidate is pushed, `Run CIT-N` should
automatically delegate one fresh independent reviewer when the harness supports
per-role delegation. The reviewer must:

- use GPT-6 Sol / HIGH;
- start in a fresh context;
- treat runtime/test source as read-only;
- receive the Issue/approved Plan boundary, approved base, exact candidate SHA/diff,
  relevant contracts and validation evidence;
- not receive or rely on implementer scratch reasoning/self-review conclusions;
- independently inspect correctness, failure paths, security/privacy, compatibility and
  tests as applicable;
- bind findings or PASS to the exact reviewed SHA in GitHub PR review/comment evidence.

A changed candidate invalidates the old review and requires a new fresh reviewer.

If native fresh delegation is unavailable or cannot complete independently, do not lower
the requirement. Preserve the candidate and return:

`STATUS: TECHNICAL_REVIEW_REQUIRED`
`Task: CIT-N`
`Next: Review CIT-N`

`Review CIT-N` is therefore the manual/standalone fallback, not the normal Human step
for every Controlled task.

If review finds blocking P0/P1/P2 issues, keep corrections in the same Issue/PR. Route
the durable findings back to the same implementer at GPT-6 Sol / MEDIUM, rerun relevant
checks, push a new exact candidate and use a **new fresh reviewer** at HIGH.

CIT retains its simple bounded correction rule: allow at most two post-candidate
correction rounds. Stop earlier for the same material blocker without progress,
oscillation, unsafe continuation or architecture/scope expansion. After the bound is
exhausted or a new boundary is required, return `HUMAN_DECISION_REQUIRED` rather than
importing a more complex convergence controller.

## Acceptance Review and merge

Fast work normally reaches Acceptance after relevant exact-head checks and
`YCI Required CI` pass. Controlled work additionally requires fresh exact-candidate
Technical Review PASS.

When those prerequisites are current, hand off with:

`STATUS: ACCEPTANCE_REVIEW_REQUIRED`
`Task: CIT-N`
`Next: Acceptance Review CIT-N`

`Acceptance Review CIT-N` is a ChatGPT gate distinct from Technical Review. It reads
the current Issue boundary, total diff, exact-head CI and required SHA-bound Technical
Review, verifies there is no unapproved scope/product/architecture drift, and records
the exact accepted head in a GitHub PR review/comment. Do not mutate the source branch
solely to add an Acceptance marker.

On Acceptance PASS:

`STATUS: READY_TO_MERGE`
`Task: CIT-N`
`Next: Merge CIT-N`

A direct Human `Merge CIT-N` authorizes only merge of the unchanged accepted
candidate. Immediately before merge, reverify head/base, required checks, branch
protection, no unresolved blockers and the exact Acceptance head. Any head movement
invalidates the prior Acceptance; executable/test movement also invalidates the
applicable Technical Review.

`Finalize CIT-N` remains a convenience shortcut. It may perform Acceptance Review plus
conditional squash merge in one turn only when the candidate already identified to the
Human is unchanged and every current-head prerequisite is satisfied. It never skips
Technical Review, CI or fresh pre-merge verification.

Observe the actual GitHub merge before reporting `FINALIZED`. Merge authority remains
separate from version bumps, Git tags, GitHub Releases, WordPress.org publication,
deployment or any production mutation.

## Release boundary

Engineering commands never imply release authority. Version changes, release packaging,
tags, GitHub Releases, WordPress.org publication and deployment require separate explicit
Human authorization and the repository's release process.
