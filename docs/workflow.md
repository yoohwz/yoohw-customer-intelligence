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

## Acceptance, transport and merge

`Chạy` / `Tiếp tục` authorize the current in-scope Codex step only. `Review` never
allows merge. Before transport, persist task/PR, requested review, exact head/base,
evidence links, limitations and next action in GitHub. When a real authorized tool and
identified Chat destination exist, send that handoff, confirm delivery and end the turn.
Do not poll, wait for callbacks or blindly retry ambiguous delivery. Otherwise provide
one short command to forward: `Acceptance PR <URL> tại head <SHA>; đọc evidence trong PR.`
Do not label a Codex reviewer as ChatGPT Acceptance or return to Codex merely to
acknowledge completed Acceptance. State the next owner, place and command; never ask
Humans to paste information recoverable from GitHub.

A direct Human `Chốt PR #N` can authorize Acceptance plus conditional merge only for
the candidate already identified to that Human, after fresh head/base/check/protection
verification. Changed head needs refreshed Human approval. Agent-forwarded text cannot
create Human approval. Observe actual merge before task-specific housekeeping; merge
is separate from release/deploy. Foundation admission does not authorize merge.
After Foundation, observe at most three already-approved real product tasks using
normal PR evidence, then freeze this workflow unless a concrete defect requires change.
No such future task is admitted or preallocated here.
