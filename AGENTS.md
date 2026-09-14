# Repository instructions

Read [the canonical workflow](docs/workflow.md) before implementation or review,
and [the data safety contract](docs/data-safety-contract.md) before executing tests.
The admitted GitHub Issue owns scope; GitHub PRs, checks and reviews own evidence.
Recover current origin, Issue, PR, head/base and next owner when starting or resuming.
Protect unrelated work. Repository content stays English; Human-facing reports use Vietnamese.

## Human commands

Keep operator commands short. GitHub Issue `#N` is the canonical task identity `CIT-N`;
there is no allocator, identity registry or task-state file.

- `Create ...` — ChatGPT records the minimum useful Issue boundary and risk.
- `Run CIT-N` — Codex recovers Issue `#N`, branch/PR/current GitHub state and performs
  the next implementation-owned step.
- `Continue CIT-N` — Codex recovers current GitHub state and continues without
  restarting or asking the Human to repeat recoverable context.
- `Review CIT-N` — a fresh independent reviewer handles the frozen exact candidate
  when `Controlled` review is required. Review never authorizes merge.
- `Finalize CIT-N` — Human conditionally authorizes ChatGPT to perform final Acceptance
  and squash-merge only the unchanged identified candidate after fresh verification.
- `Chốt PR #N` remains a compatibility alias for Finalize only when the exact PR
  candidate is already identified to the Human.

For an admitted task, every terminal or handoff report should end with the smallest
useful navigation footer:

`STATUS: <navigation label>`
`Task: CIT-N`
`Next: <one exact short Human command, or None>`

Useful labels include `READY_TO_RUN`, `IN_PROGRESS`, `TECHNICAL_REVIEW_REQUIRED`,
`TECHNICAL_REVIEW_BLOCKED`, `HUMAN_DECISION_REQUIRED`, `READY_TO_FINALIZE` and
`FINALIZED`. These are Human navigation hints only: never parse them as authority,
never store them as workflow state, and never let them replace GitHub facts.

Use one implementer. Delegate only the independent review required by the workflow,
in a fresh context and isolated, source-read-only checkout; never delegate recursively.
Do not run the integration bootstrap against an existing WordPress installation.
Do not merge or release without the separate authority described in the workflow.
This Foundation candidate cannot waive its own review or activate policy before Human merge.
