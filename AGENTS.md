# Repository instructions

Read [the canonical workflow](docs/workflow.md) before implementation or review,
and [the data safety contract](docs/data-safety-contract.md) before executing tests.
The admitted GitHub Issue owns scope; GitHub PRs, checks and reviews own evidence.
For governance amendments, the workflow accepted on the admitted base owns authority;
an unmerged amendment cannot authorize or waive its own execution requirements.
Recover current origin, Issue, PR, head/base and next owner when starting or resuming.
Protect unrelated work. Repository content stays English; Human-facing reports use Vietnamese.

## Human commands

Keep operator commands short. GitHub Issue `#N` is the canonical task identity `CIT-N`;
there is no allocator, identity registry or task-state file.
For `Run CIT-N` and `Continue CIT-N` in a known repository, take numeric `N` directly
to GitHub Issue `#N` first, then recover the branch/PR and current evidence from GitHub.
Search repository text for `CIT-N` only if the Issue is missing/unreadable, the repository
is uncertain or GitHub facts materially conflict; a search never replaces a consistent Issue.

- `Create ...` — ChatGPT records the minimum useful Issue boundary and risk.
- `Run CIT-N` — Codex recovers current GitHub state and performs the next
  implementation-owned step. For `Controlled` work it also delegates the required
  fresh Technical Reviewer after an exact candidate is pushed when supported.
- `Continue CIT-N` — recover current GitHub state and resume the same task without
  restarting or asking the Human to repeat recoverable context.
- `Plan Review CIT-N` — ChatGPT resolves a genuinely unresolved product, architecture,
  data, permission or compatibility boundary. It is not required for every Controlled task.
- `Review CIT-N` — manual/standalone fresh Technical Review fallback for a frozen
  Controlled candidate. Review never authorizes merge.
- `Acceptance Review CIT-N` — ChatGPT performs exact-head external Acceptance and
  records the verdict in GitHub. Acceptance never authorizes release.
- `Merge CIT-N` — Human authorizes merge of the unchanged accepted candidate after
  fresh verification.
- `Finalize CIT-N` — convenience compatibility shortcut authorizing Acceptance plus
  conditional squash merge of the unchanged identified candidate in one turn, except
  for workflow-governance amendments, which require separate Acceptance and Human Merge.
- `Chốt PR #N` remains a compatibility alias for Finalize only when the exact PR
  candidate is already identified to the Human.

For an admitted task, every terminal or handoff report should end with the smallest
useful navigation footer:

`STATUS: <navigation label>`
`Task: CIT-N`
`Next: <one exact short Human command, or None>`

Useful labels include `READY_TO_RUN`, `IN_PROGRESS`, `PLAN_REVIEW_REQUIRED`,
`TECHNICAL_REVIEW_REQUIRED`, `TECHNICAL_REVIEW_BLOCKED`,
`ACCEPTANCE_REVIEW_REQUIRED`, `READY_TO_MERGE`, `HUMAN_DECISION_REQUIRED`
and `FINALIZED`. These are Human navigation hints only: never parse them as authority,
never store them as workflow state, and never let them replace GitHub facts.

## Compute and delegation

Compute is selected by phase, not stored as task lifecycle state.

- Root / ordinary implementation / correction: GPT-6 Sol / MEDIUM.
- Controlled discovery or architecture when a separate Plan Review is actually needed:
  GPT-6 Sol / HIGH.
- Every fresh independent Technical Reviewer and re-reviewer: GPT-6 Sol / HIGH.
- GPT-6 Sol / XHIGH is exceptional and requires a specific unresolved
  architecture/security reason.
- GPT-6 Astra is exceptional manual escalation, not a governed default.

Reduce irrelevant context before increasing compute. Do not switch models for shell/Git/test
substeps inside a phase.

Use one implementer. For Controlled work, delegate only the required independent reviewer,
in a fresh context and source-read-only checkout/environment. The reviewer receives the
Issue/approved boundary, approved base, exact candidate SHA/diff and required validation,
not implementer scratch reasoning or self-review conclusions. A changed candidate requires
a new fresh reviewer. Never delegate recursively.

If fresh reviewer delegation is unavailable, preserve the candidate and stop at
`TECHNICAL_REVIEW_REQUIRED` with `Next: Review CIT-N`; never weaken independence to
avoid a manual review.

Workflow-governance semantic changes are always `Controlled`, even in Markdown.
Risk lane and tool access never transfer implementation ownership: Codex implements;
ChatGPT frames, resolves boundaries and accepts; Human authorizes merge and release.
Governance amendments require Codex implementation, fresh exact-candidate Technical
Review, exact-head `YCI Required CI`, ChatGPT Acceptance Review and separate Human Merge.

Do not run the integration bootstrap against an existing WordPress installation.
Do not merge or release without the separate authority described in the workflow.
