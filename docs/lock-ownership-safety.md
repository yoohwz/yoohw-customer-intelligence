# Identity and migration lock ownership

Creation locks cover every non-empty canonical user ID, email and phone. The existing
normalizers and identity resolution/assignment precedence are unchanged. Names are
hashed with the database and table-prefix namespace, deduplicated and sorted before
nonblocking acquisition. A failed attempt releases only its acquired subset. No
customer-ID substitute, unique index or historical duplicate repair is introduced.

The small helper in `YoOhw_COS_DB` shares the identity/migration ownership operation;
it does not add a queue, lease service, configurable lock framework or new schema.
MySQL named locks own the boundary on the acquiring connection. An opaque random
handle tracks that attempt locally, including its connection and complete key set.
Same-connection reentrancy is refused. Release checks the handle's current local
ownership and original connection, and MySQL itself refuses another connection's
release. A stale handle cannot release a replacement owner's lock, even if the same
connection has legitimately reacquired a key under a new handle.

These locks have no TTL or expiry takeover. A live slow owner remains exclusive;
normal `finally` release or the owning database connection ending makes acquisition
possible again. Old timestamp option rows, including expired/malformed values, are
not ownership authority and are neither trusted through object cache nor deleted by
this path. No old option can authorize release of a current native owner. This is an
intentional change from expiring option leases, avoiding concurrent work when a live
operation outlasts a timestamp. Tests simulate a native ownership end while retaining
the old PHP handle, then replacement acquisition and late release across real
connections; they do not claim a still-owned native lock can expire.

Order sync retains the existing Reset boundary. The complete creation lock set is
held for final re-resolution and create/update, and released on success, conflict,
early return and exception. A non-empty identity that cannot acquire its set uses
the existing bounded `yoohw_cos_retry_order_sync` scheduling path. Inputs with no
creation identity remain unresolvable rather than scheduling an endless empty retry.
Repeated retries preserve the existing idempotent order facts, links and events.

The migration batch receives a local ownership handle and releases it in its existing
`finally`. Schema readiness, Reset ordering, batching, state/provenance, cursor and
issue-accounting semantics are unchanged. The existing Reset lock also serializes
ordinary sync workers before they reach creation: tests must distinguish that public
path protection from independent overlapping-key and stale-owner API defects, without
bypassing Reset to manufacture a race.

Evidence uses only the guarded disposable MySQL runner and existing admitted process
entrypoint, synthetic orders and identities, controlled barriers and persisted reads.
Legacy option fixtures, partial acquisition, exceptions, replacement ownership,
non-overlap and actual order retry complement the full HPOS matrix and Reset/schema
regressions. This does not certify arbitrary database proxies, mixed-version direct
lock API callers, every runtime/engine, crash timing or production-scale contention.
No live store, real email, deployment or data repair is involved.
