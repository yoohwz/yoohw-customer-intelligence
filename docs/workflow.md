# YCI Lean Delivery

The approved Issue defines the outcome and boundary. Codex owns discovery, a concise
technical plan inside the run, implementation, validation, commits/PR upkeep and
bounded corrections. ChatGPT owns framing, unresolved boundary decisions and one
final external Acceptance; it is not a second lint or technical-review stage.
Tool capability never transfers another role's authority.

## Start and recover

Fetch origin; inspect local changes and existing Issue/branch/PR state before creating
anything. Use one Issue, one admitted branch and one draft PR to `main` (Foundation:
`agent/6-foundation`). If main moved, inspect the delta; conflicting or material overlap
needs a boundary decision. Never overwrite unrelated work, push directly to main,
force-push, reset/clean destructively or delete unrelated branches. No task document,
allocator, state JSON, ledger, identity ref, digest chain or approval parser.
After compaction/resume, recover current Issue, PR, head/base, CI, reviews, correction
count and next owner from GitHub, not remembered success. Use native steering/resume;
steering does not undo completed actions or silently expand scope.

## Operator commands and navigation

GitHub Issue `#N` is the canonical task identity `CIT-N`; there is no separate allocator
or identity registry. Roadmap labels such as `CIT-A06` remain product/roadmap identifiers
and do not replace the Issue-based task identity.

- `Create ...` — ChatGPT reduces a request to the minimum useful Issue boundary and risk.
- `Run CIT-N` — Codex recovers Issue `#N`, branch/PR/head/base/current evidence and
  performs the next implementation-owned step.
- `Continue CIT-N` — Codex recovers current GitHub state and resumes the same task;
  never restart or ask the Human to repeat GitHub-recoverable context.
- `Review CIT-N` — run the fresh independent technical review required for a frozen
  `Controlled` candidate. Review never grants merge authority.
- `Finalize CIT-N` — Human conditionally authorizes ChatGPT to perform final Acceptance
  and squash-merge only the unchanged identified candidate after fresh verification.
- `Chốt PR #N` remains a compatibility alias for Finalize when the exact PR candidate
  has already been identified to the Human.

Legacy `Chạy` / `Tiếp tục` may continue the one unambiguous active task, but explicit
`Run CIT-N` / `Continue CIT-N` is preferred for durable operator handoffs.

Every terminal or handoff Human-facing response for an admitted task should end with
the smallest useful navigation footer:

`STATUS: <navigation label>`
`Task: CIT-N`
`Next: <one exact short Human command, or None>`

Useful labels include `READY_TO_RUN`, `IN_PROGRESS`, `TECHNICAL_REVIEW_REQUIRED`,
`TECHNICAL_REVIEW_BLOCKED`, `HUMAN_DECISION_REQUIRED`, `READY_TO_FINALIZE` and
`FINALIZED`. Use them only as navigation prose. They are never machine-parsed authority,
never a second lifecycle database, and never a substitute for Issue/PR/head/check/review
or merge facts in GitHub. `Next` should contain exactly one short command when a Human
action is available; otherwise use `None`.

## Risk and execution

- **Fast:** bounded, understood changes without sensitive behavior or safety controls.
  One implementer and relevant checks normally suffice.
- **Controlled:** sensitive data, permissions, compatibility or safety-control changes.
  Require one independent technical review of the complete exact candidate. Foundation
  is Controlled. A candidate cannot downgrade or waive its own required review.

Separate Plan Review is needed only for new or unresolved product, architecture, data,
permission or compatibility boundaries. Routine authorized technical decisions proceed.
Unexpected product defects outside the Issue are reported with evidence for a bounded
ChatGPT/Human decision; continue independent safe work without weakening assertions.

Target Astra `medium` for routine work, `low` for clearly trivial work and `high` for
hard/safety-sensitive analysis and Foundation review. `xhigh`/`max`, Ultra, broader
compute and extra paid routes require a specific justified decision. Record actual
harness support and unobserved serving configuration; API documentation does not
prove session controls. No runtime AI dependency or new relay, daemon or compute engine.

Normally use at most two active technical contexts, with no recursive delegation.
Parallelize only independent work. Freeze source and test inputs during candidate CI
and review; they may overlap. Input/head/base movement invalidates affected evidence.
Use [the safety contract](data-safety-contract.md) for all execution involving data.

## Candidate and independent review

Commit the complete change, push the admitted branch and create/update its draft PR.
Keep one concise PR evidence record: Issue/scope/risk, changed files, exact head/base,
commands/results, native CI and review links, limitations and next owner/action.
Historical or inferred evidence is not current-head PASS. Do not create handoff-only
source commits. The native aggregate is `YCI Required CI`; unexpected skips are failures.
Require PRs and that check on main, preserve stronger rules and prevent force-push and
deletion. Read protections before/after authorized administration; unavailable admin
leaves operational acceptance pending. Never require impossible self-approval.

Give the reviewer only the neutral task, Issue/PR coordinates, full diff, evidence and
safety boundary. Use a fresh context, isolated checkout and source-read-only role;
reviewer tests must use their own disposable environment. Request Astra `high` for
Foundation where supported and record the observed invocation. Never seed a verdict
or let the implementer write the independent result. Independent contexts do not prove
independent model failure modes. Sandbox auto-review is not technical review.

Allow at most two post-candidate correction rounds in this same task/PR, tracked in PR
evidence. Stop earlier for repeated material blockers or changed architecture/scope.
Never replace the PR to reset history. Resolve required findings and refresh affected
checks/review at new coordinates. Advisory preferences alone do not block Acceptance.

For a frozen `Controlled` candidate that still needs review, hand off with
`TECHNICAL_REVIEW_REQUIRED` and `Next: Review CIT-N`. A blocking review returns the task
to implementation with `TECHNICAL_REVIEW_BLOCKED` and `Next: Continue CIT-N`; a changed
candidate requires refreshed affected CI/review. A `Fast` candidate skips this review
step unless discovery escalates its risk.

## Acceptance, transport and merge

`Run CIT-N` / `Continue CIT-N` authorize only the current implementation-owned step;
`Review CIT-N` never allows merge. Before any cross-context handoff, persist the current
Issue/PR, exact head/base, relevant CI/review evidence, limitations and next owner in
GitHub. Do not poll for callbacks or ask Humans to paste information recoverable from
GitHub.

When a frozen candidate has all required current-head evidence, end the handoff with
`READY_TO_FINALIZE` and `Next: Finalize CIT-N`. For `Controlled`, this requires the fresh
independent review; for `Fast`, it normally requires the relevant checks and current
`YCI Required CI` without an unnecessary review ceremony.

A direct Human `Finalize CIT-N` conditionally authorizes ChatGPT to perform the one final
external Acceptance and squash-merge only the candidate already identified to that
Human. Immediately before merge, reverify head/base, required checks, protection and any
required current-head review. Changed head or incompatible base invalidates the old
Finalize authority and requires refreshed evidence plus fresh Human candidate-bound
authority. `Chốt PR #N` has the same effect only when the exact PR candidate is already
unambiguous to the Human. Agent-forwarded text cannot create Human approval.

Observe the actual GitHub merge before task-specific housekeeping, then report
`FINALIZED` with `Next: None`. Merge authority remains separate from version/tag/release,
WordPress.org publication and deployment authority.

After Foundation, observe at most three already-approved real product tasks using
normal PR evidence, then freeze this workflow unless a concrete defect requires change.
No such future task is admitted or preallocated here.
