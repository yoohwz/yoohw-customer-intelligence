# Schema upgrade safety

The database target is `0.2.2`. Both `install()` and `maybe_update()` attempt the
existing idempotent DDL and use the same current-schema postcondition verifier before
registering data migrations or recording `yoohw_cos_db_version`. A failed attempt keeps
the previous (or absent) DB version. `yoohw_cos_version` retains its separate plugin
version meaning and is not evidence of schema readiness.

The explicit manifest describes only this plugin's eleven current tables: required
column types, nullability/defaults and auto-increment identities; named indexes with
exact ordered columns, uniqueness, full-column coverage, ascending BTREE shape and
visibility where the server exposes it. Integer display widths are normalized because
they do not change the required integer type. Extra columns/indexes are not removed.
The verifier reads actual metadata; neither dbDelta messages, a stored ready flag nor
the version option authorizes a migration by itself.

`yoohw_cos_schema_status` is one diagnostic option with `status` (`ready`/`blocked`),
`target_version`, `requirements` and `last_attempt_at`. Requirement codes are fixed
manifest identifiers such as `tasks.index.source_key`, or bounded `ddl.ensure` /
`schema.read` codes; they contain no raw SQL, error text, credentials, row data or
stack trace. A successful retry resolves blocked requirements. Last attempt records
the DDL attempt (or first observation), not every repeated readiness read.

Migration registration, scheduling and direct/manual execution recheck schema readiness.
Blocked execution does not acquire the migration lock, consume cursors, supersede
registrations or update issue accounting. A queued old hook may still fire but exits
without executing a batch or scheduling a retry loop. Normal init/activation schedules
existing pending work after schema readiness returns; registration preserves existing
progress and the original same-target schema metadata. Lock ownership is unchanged.

Old-version upgrades retry on the next ordinary request. A current version also gets
an actual readiness check: valid schema is a DDL-free no-op, while detected drift can
retry the existing DDL. A stored version newer than this plugin target is never
downgraded by `install()` or `maybe_update()`: both check readiness for migration
gating without applying older DDL or resetting registrations. The shared upgrade
entrypoint enforces this before any DDL, including activation after a plugin rollback. Partial successful changes remain for the next attempt. The
plugin never drops data/indexes to resolve a blocker. For example, duplicate non-null
`source_key` rows block a required unique index; support must resolve the data blocker
under a separately authorized policy. This change provides truthful status, not an
automatic duplicate repair or historical rewrite.

The 0.2.2 schema adds nullable order-fact currency and explicit customer monetary state
and currency columns, plus a readiness marker for currency-aware derived intelligence.
Existing rows default to unknown and unready; they are never assigned the
current store currency. The existing bounded migration worker runs
`commerce_currency_v3` after earlier migrations: it reloads orders through WooCommerce,
retries failed items, then rebuilds customers. Revenue and AOV remain unavailable while
backfill is incomplete or has unresolved issues. A successful order/customer repair can
resolve its recorded issue; once all issues resolve, the worker's state becomes complete
and the bounded intelligence refresh recalculates money-derived decisions.
Commerce migrations pause without WooCommerce order APIs and resume when WooCommerce
becomes available; an unavailable order source cannot count as an empty completed scan.
On a fresh install, the existing `commerce_facts_v2` import is the currency source;
monetary consumers remain unavailable until that import completes without unresolved
issues, and its completion or final repair restarts money-derived intelligence.

Tests use the guarded owned MySQL runner, synthetic duplicate rows, and metadata/data
snapshots. They cover blocked activation/upgrade, persisted migrations, retry,
idempotency, incomplete/wrong indexes and tables/columns, and fresh schema creation.
Fixture-only table backups preserve original synthetic rows while fresh tables are
created and removed inside the disposable database. Existing mail/HTTP interception,
grant controls, unrelated database sentinel and owned cleanup remain unchanged. This
is pinned WP/WC plus MySQL 8 evidence, not universal engine/runtime, live-store,
concurrent-DDL serialization, or release certification.
