# CIT-58 synthetic large-data benchmark

## Reproduce

Run from a clean checkout after `composer install --no-plugins --no-scripts`:

```sh
python3 scripts/test-isolated.py --mysql-bin /path/to/mysql-8.4/bin --php /path/to/php-8.4 --mode yes --benchmark-only
```

The runner provisions a private socket-only MySQL server, a random database and a
restricted account. It checks an ownership token, live grants and rejected entrypoints
before WordPress loads. `tests/benchmark.php` repeats the guard before bootstrap.
Optional `--inputs /path/to/archive-cache` still requires pinned archive SHA-256
checksums. The ordinary CI runner executes the 40-profile safety/behavior smoke in
both HPOS modes; the large profile is opt-in. No existing WordPress installation or
customer data is used.

## Measured environment and rows

2026-09-25, macOS arm64 owned temporary MySQL 8.4.0, PHP 8.4.26, WordPress 6.9,
WooCommerce 10.8.0, HPOS=yes. Five measured calls followed one warm-up call per
path; wall times include PHP and SQL. Fixture setup is excluded from timings.

| Customer Intelligence table | Exact rows |
| --- | ---: |
| Profiles | 25,000 |
| Order facts | 100,000 |
| Tasks | 50,000 |
| Events | 100,000 |
| Customer-tag links | 8,333 |
| Customer-segment links | 6,250 |

The fixture uses only deterministic synthetic identities at `example.test`. Half
the profiles have 2 order facts, 100 in spend and an older order date; the other
half have 6 order facts, 300 in spend and a recent date. Task status and due
dates are mixed, including customers with no open task.

## Results

Query count was identical across all five measured calls for each path. The harness
checks exact totals, unique first-page IDs, disjoint second pages and constant query
count at page sizes 1 and 20.

| Path | Queries | Median ms | Exact matching profiles | Dominant `EXPLAIN` |
| --- | ---: | ---: | ---: | --- |
| Default Customers first page and count | 3 | 37.076 | 25,000 | `c`: `ref`, `archived_at`, ~12,500 rows; filesort |
| Status/lifecycle/value/risk composition | 4 | 13.170 | 238 | `c`: `ref`, `customer_status`, ~5,000 rows; filesort |
| `open_follow_up` | 3 | 96.989 | 22,500 | `c`: `ref`, `archived_at`; `t`: `ref`, `customer_id`, ~1 row, FirstMatch |
| `overdue_follow_up` | 3 | 102.741 | 15,001 | `c`: `ref`, `archived_at`; `t`: `ref`, `customer_id`, ~1 row, FirstMatch |
| `high_value_needs_follow_up` | 4 | 66.405 | 357 | `c`: `ref`, `archived_at`; `t`: `ref`, `customer_id`, ~1 row, Not exists |
| R/F/M thresholds | 4 | 58.069 | 12,500 | `c`: `ref`, `archived_at`, ~12,500 rows; filesort |
| Overview summary and attention | 5 | 93.564 | n/a | customer aggregate: `ref`, `archived_at`, ~12,500 rows |
| Diagnostics snapshot | 45 | 68.646 | n/a | customer aggregate: `ALL`, 25,000 rows |

The customer aggregate in Diagnostics intentionally reads every profile to count
archive, currency and generation states. The Overview scans active profiles for
table-wide totals. Those scans are expected. The customer-list filesorts apply to
the current sort order and are below the 1-second interactive threshold in this
environment. The task relationship plans probe `customer_id` with about one
estimated row; they do not repeatedly scan the task table. No index was added.

## Evidence-based correction

The first large run on the same owned MySQL 8.4.0 dataset scale, with PHP 8.5.10,
showed one scoring-generation SQL read per returned customer: 22 queries for the
default and follow-up first pages, 23 for the composition and RFM pages, versus 3
when a path returned no rows. This met the Issue's query-count growth criterion.
The production correction reads the generation once per query result and passes it
to each row's safety check. A repeat large run on PHP 8.5.10 measured 3 queries for
default/open/overdue and 4 for composition/RFM. The PHP 8.4.26 results above confirm
the same fixed counts on the CI runtime. Existing customer-query filters and safety
decisions remain in their canonical classes; no persistent cache was introduced.

The owned runner completed its negative ownership/grant controls, verified an
unrelated synthetic DB sentinel, and removed its MySQL process and temporary files.
The full HPOS=yes and HPOS=no integration suites each passed 219 tests and the
40-profile benchmark smoke.
