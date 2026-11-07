# YCI Lean Delivery

The Human-authorized Issue defines the outcome and boundary. Codex Main owns Issue
framing, discovery, technical design, implementation, subagent orchestration, validation,
PR upkeep, corrections and completion verification. ChatGPT has no mandatory role.
Human owns product priorities, material product/scope/support-contract decisions,
acceptance of unresolved material risk, and merge, release, deployment and rollback.
Tool capability never transfers authority. Engineering completion never authorizes merge
or production changes.

## Start and recover

Fetch origin; inspect local changes and existing Issue/branch/PR state before creating
anything. Use one Issue, one admitted branch and one PR to `main`. If main moved,
inspect the delta; conflicting or material overlap needs a boundary decision. Never
overwrite unrelated work, push directly to main, force-push, reset/clean destructively
or delete unrelated branches.

For any workflow-governance amendment, the workflow accepted on the task's admitted
base governs admission, implementation, review, Acceptance and merge. Proposed or
unmerged text cannot grant, waive, downgrade or redefine authority for its own task.
The amendment takes effect only after Human-authorized merge. This Codex-owned workflow
applies to tasks admitted after that merge. Earlier tasks, including CIT-112 itself,
retain their admitted rules and approval provenance; do not silently migrate them,
rewrite historical verdicts or infer a missing approval from this document.

No task document, allocator, state JSON, ledger, identity ref, digest chain or approval
parser exists. After compaction/resume, recover current Issue, PR, head/base, CI, reviews,
correction count and next owner from GitHub, not remembered success.

## Operator commands and navigation

GitHub Issue `#N` is the canonical task identity `CIT-N`; roadmap labels such as
`CIT-A06` remain product/roadmap identifiers and do not replace Issue identity.

For `Run CIT-N` and `Continue CIT-N` in a known repository, use the numeric `N` as the
GitHub Issue number and open Issue `#N` directly first. From that Issue and current
GitHub state, recover admitted scope/risk, acceptance boundary, base, existing branch
and PR, exact head/base, CI/review evidence and the next authorized step or
handoff. Do not start by searching source/repository text for `CIT-N`. Search by task
code only if the Issue is missing or unreadable, repository identity is uncertain, or
GitHub Issue/branch/PR facts materially conflict and source inspection can reconcile
them. A fallback search never supersedes an existing, consistent Issue as task authority.

- `Create ...` — Codex frames the minimum useful Issue from the Human goal: outcome,
  scope/exclusions, invariants, acceptance criteria, risk and admitted base. A clear
  Human request authorizes routine framing and in-scope execution without another gate.
- `Run CIT-N` — Codex continues through discovery, implementation, relevant validation,
  required fresh review, corrections, CI and completion verification without requiring
  Human commands between internal stages.
- `Continue CIT-N` — recover GitHub state and resume the same task, with no new authority.
- `Review CIT-N` — manual/standalone fresh Technical Review fallback when native
  delegation is unavailable. It never grants merge authority.
- `Merge CIT-N` — Human authorizes merge of the unchanged verified candidate after
  fresh pre-merge verification. It never authorizes release.

For new-policy tasks, legacy `Plan Review CIT-N` requests Codex technical assessment;
`Acceptance Review CIT-N` requests Codex completion verification. Neither is a required
external stage, grants merge authority or restarts the task. `Finalize CIT-N` and
`Chốt PR #N` remain explicit Human merge shortcuts only for an unchanged candidate
already identified to Human and satisfying all current prerequisites. Governance
amendments still require a separate Human Merge after completion verification.
Earlier tasks retain the command semantics required by their admitted workflow.

Legacy `Chạy` / `Tiếp tục` may continue one unambiguous active task, but explicit
Issue-based commands are preferred for durable handoffs.

For an admitted task, use a concise footer when handing work to Human:

`STATUS: <navigation label>`
`Task: CIT-N`
`Next: <one exact short Human command, or None>`

Useful labels are navigation prose only, not machine state:
`READY_TO_MERGE`, `HUMAN_DECISION_REQUIRED`, `TECHNICAL_REVIEW_REQUIRED`
and `FINALIZED`. Internal progress needs no command handoff. Earlier tasks may retain
the navigation labels required by their admitted policy.

## Risk and execution

- **Fast:** bounded, understood work without sensitive behavior or safety-control
  semantics. One implementer and relevant checks normally suffice. No independent
  reviewer ceremony by default.
- **Controlled:** sensitive data, permissions, security/privacy, persistence,
  compatibility-sensitive contracts, release safety or equivalent material controls.
  Require one fresh independent Technical Reviewer for every exact candidate that
  reaches review.

Workflow-governance semantic changes are always Controlled. This includes changes to
`AGENTS.md` or this canonical workflow that alter authority or execution semantics,
role ownership/separation, risk routing, compute/delegation policy, Technical Review,
Plan Review/Acceptance/Merge authority, operator-command semantics or release/deployment
authority boundaries. Classify by semantics, not file type. Proven typo, formatting or
link fixes with no semantic change may remain Fast. Neither lane changes role ownership.

Codex resolves technical architecture, data handling, permissions, compatibility
approaches and test design within the accepted outcome and contracts. No separate
Plan Review is mandatory. Record a concise technical plan in the existing Issue/PR
only when complexity or continuity warrants it. Escalate material product/policy,
scope/support-contract changes or unresolved material risk directly to Human with
evidence, options and a recommendation; continue independent safe work where possible.
Do not ask Human to choose routine implementation details or restart an internal stage.

If Fast discovery reveals a Controlled trigger, stop before the sensitive change,
keep the same Issue/PR, escalate risk and continue under the Controlled rules.

A new-policy governance amendment requires Codex implementation, fresh independent
Technical Review, exact-head `YCI Required CI`, Codex completion verification and
separate Human Merge. Its admitted-base rules govern the amendment itself.

## Compute policy

Compute follows phase rather than mutable task metadata:

- Root / main orchestration: GPT-6.1 Sol / MEDIUM.
- Fast implementation: GPT-6.1 Sol / MEDIUM.
- Controlled deterministic implementation and correction after the boundary is settled:
  GPT-6.1 Sol / MEDIUM.
- Controlled discovery/architecture with a genuinely unresolved technical boundary:
  GPT-6.1 Sol / HIGH.
- Independent Technical Review, including every fresh re-review: GPT-6.1 Sol / HIGH.
- GPT-6.1 Sol / XHIGH is exceptional for a specific unresolved architecture/security
  problem and requires an explicit reason.
- GPT-6 Astra requires explicit exceptional manual escalation and is not the governed
  default.

Reduce irrelevant context before increasing compute. Do not serialize model, effort or
execution history into the Issue merely because a phase used them, and do not change
models for individual shell/Git/lint/test commands inside a phase.

## Subagent orchestration

Codex Main remains accountable for the whole outcome. Use one default writer and
optional read-only support agents for useful independent discovery, compatibility,
security or test inspection. Delegate implementation only when write scopes and test
resources are clearly disjoint; avoid concurrent writes to the same files or shared
mutable test state. Main defines each assignment's goal, scope, output and completion
condition, resolves conflicts and verifies the integrated result. Human does not relay
prompts or coordinate agents. Do not add mandatory agent stages or an orchestration system.
Do not delegate recursively.

Support agents are contributors, not independent reviewers of their own candidate.
Anyone contributing to discovery, design, implementation or correction is ineligible
to provide that candidate's independent Technical Review. A Controlled candidate always
requires a distinct fresh reviewer under the rules below.

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

- use GPT-6.1 Sol / HIGH;
- start in a fresh context;
- treat the entire reviewed source as read-only, in a separate checkout/environment;
- receive the Issue/approved Plan boundary, approved base, exact candidate SHA/diff,
  relevant contracts and validation evidence;
- not receive or rely on implementer scratch reasoning/self-review conclusions;
- independently inspect correctness, failure paths, security/privacy, compatibility,
  tests, Issue acceptance criteria and unnecessary solution complexity as applicable;
- bind findings or PASS to the exact reviewed SHA in GitHub PR review/comment evidence.

A changed candidate invalidates the old review, including for documentation-only edits,
and requires a new fresh reviewer.

If native fresh delegation is unavailable or cannot complete independently, do not lower
the requirement. Preserve the candidate and return:

`STATUS: TECHNICAL_REVIEW_REQUIRED`
`Task: CIT-N`
`Next: Review CIT-N`

`Review CIT-N` is therefore the manual/standalone fallback, not the normal Human step
for every Controlled task.

Correctness, safety, regression, contract or acceptance failures are blockers.
Optional cleanup, naming preferences and speculative refactors are advisory and do not
create mandatory correction cycles.

If review finds blocking P0/P1/P2 issues, keep corrections in the same Issue/PR. Route
the durable findings back to the same implementer at GPT-6.1 Sol / MEDIUM, rerun relevant
checks, push a new exact candidate and use a **new fresh reviewer** at HIGH.

Continue correction, validation and fresh re-review without a numeric round cap while
each round makes material progress on current in-scope findings. Stop and return
`HUMAN_DECISION_REQUIRED` for no material progress, a repeated or stagnant material
blocker, oscillation, unsafe continuation or material product/scope/support-contract
expansion requiring Human decision. Routine technical redesign within scope remains
Codex-owned.

## Completion verification and merge

Fast work reaches completion verification after relevant exact-head checks and
`YCI Required CI` pass. Controlled work additionally requires fresh exact-candidate
Technical Review PASS, covering both technical correctness and Issue acceptance criteria.

Codex Main checks the current Issue boundary, total diff, exact head/base, CI and required
review evidence, unresolved findings, acceptance criteria and limitations. Record one
concise SHA-bound completion verdict in the existing PR evidence; do not add a separate
mandatory Acceptance agent, status-only source commit or parallel approval record.
Missing evidence is not PASS. Report material limitations and residual risks accurately;
unresolved material risk requiring acceptance belongs to Human.

When all prerequisites are current, hand off with:

`STATUS: READY_TO_MERGE`
`Task: CIT-N`
`Next: Merge CIT-N`

A direct Human `Merge CIT-N` authorizes only merge of the unchanged verified candidate.
Immediately before merge, reverify head/base, required checks, branch protection,
no unresolved blockers and the exact completion-verification head. Any head movement
invalidates prior completion verification and applicable Technical Review, regardless
of file type. If the base changes, inspect the delta and refresh affected validation
and review before declaring readiness; do not infer compatibility from an older PASS.

`Finalize CIT-N` or `Chốt PR #N` may verify and conditionally squash merge only when
Human has already identified and authorized the unchanged candidate and all current
prerequisites pass. Governance amendments require completion verification followed by
a separate Human Merge. No convenience command bypasses required review or CI.
Earlier tasks retain their admitted external Acceptance requirements.

Observe the actual GitHub merge before reporting `FINALIZED`. Merge authority remains
separate from version bumps, Git tags, GitHub Releases, WordPress.org publication,
deployment or any production mutation.

## Release boundary

Engineering commands never imply release authority. Version changes, release packaging,
tags, GitHub Releases, WordPress.org publication and deployment require separate explicit
Human authorization and the repository's release process.
