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

- `Create ...` — Codex frames the minimum useful Issue from the Human goal.
- `Run CIT-N` / `Continue CIT-N` — Codex resumes the same outcome and continues through
  implementation, validation, required independent review, corrections, CI and completion
  verification without Human commands between internal stages.
- `Review CIT-N` — fresh Technical Review fallback; no merge authority.
- `Merge CIT-N` — Human authorizes the unchanged verified candidate after fresh checks.

For new-policy tasks, `Plan Review` and `Acceptance Review` are optional compatibility
requests to Codex, not mandatory external gates. `Finalize CIT-N` / `Chốt PR #N` retain
candidate-bound explicit Human merge semantics; governance amendments require separate
Human Merge. Follow the canonical workflow for compatibility and navigation details.

Codex owns technical choices within the Human-authorized outcome. Ask Human only for
material product/scope/support-contract decisions, unresolved material risk or authority
not already granted. Human retains priorities, merge, release, deployment and rollback.
ChatGPT has no mandatory role for tasks admitted after this amendment's authorized merge.
Earlier tasks, including CIT-112 itself, retain their admitted rules and approval provenance.

## Compute and delegation

Compute is selected by phase, not stored as task lifecycle state.

- Root / ordinary implementation / correction: GPT-6.1 Sol / MEDIUM.
- Controlled discovery or architecture with a genuinely unresolved technical boundary:
  GPT-6.1 Sol / HIGH.
- Every fresh independent Technical Reviewer and re-reviewer: GPT-6.1 Sol / HIGH.
- GPT-6.1 Sol / XHIGH is exceptional and requires a specific unresolved
  architecture/security reason.
- GPT-6 Astra is exceptional manual escalation, not a governed default.

Reduce irrelevant context before increasing compute. Do not switch models for shell/Git/test
substeps inside a phase.

Codex Main owns orchestration and verification. Use one default writer, optional useful
read-only support agents, and implementation delegates only with clearly disjoint write
scopes and isolated test resources. Define each assignment's goal, scope, output and
completion condition. Do not require Human to coordinate agents or relay prompts.
Never delegate recursively.

For Controlled work, use a distinct fresh reviewer in a separate source-read-only
checkout/environment. No contributor to discovery, design, implementation or correction
may review that candidate independently. Provide the Issue/accepted boundary, approved
base, exact candidate SHA/diff, contracts and validation evidence, not implementer scratch
reasoning or self-review conclusions. Review covers correctness and acceptance criteria;
Main verifies final evidence without another mandatory Acceptance agent. A changed
candidate requires a new fresh reviewer.

If fresh reviewer delegation is unavailable, preserve the candidate and stop at
`TECHNICAL_REVIEW_REQUIRED` with `Next: Review CIT-N`; never weaken independence to
avoid a manual review.

Workflow-governance semantic changes are always `Controlled`, even in Markdown.
New-policy amendments require Codex implementation, fresh exact-candidate Technical
Review, exact-head `YCI Required CI`, Codex completion verification and separate Human
Merge. The admitted-base workflow governs each amendment itself; proposed text cannot
waive its requirements. Keep all existing data-safety and release boundaries.

Do not run the integration bootstrap against an existing WordPress installation.
Do not merge or release without the separate authority described in the workflow.
