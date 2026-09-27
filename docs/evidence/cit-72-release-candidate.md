# CIT-72 Free 1.4.0 release candidate

Prepared on 2026-09-27 (Asia/Ho_Chi_Minh) from admitted protected `main@f6adfaad0f22da38b34c9dfa87293cbd94302b15`. The exact committed candidate SHA, PR, exact-head `YCI Required CI` run IDs, and independent Technical Review belong in GitHub PR evidence because adding them here after commit would move the candidate. Target release version: `1.4.0`.

## Release identity and public notes

| Identity | Exact staged value |
| --- | --- |
| Plugin header `Version` | `1.4.0` |
| `YOOHW_COS_VERSION` | `1.4.0` |
| Readme `Stable tag` and latest Changelog heading | `1.4.0` |
| Changelog latest heading | `1.4.0 (Sep 27, 2026)` |
| `YOOHW_COS_DB_VERSION` | `0.2.4`, unchanged |

The readme's latest heading is `= 1.4.0 =` with a neutral date line, `Sep 27, 2026.`, so the existing release publisher's `changelog_notes()` extracts the public notes instead of falling back to a generic version sentence. `changelog.txt` retains the entire prior history byte-for-byte below the new entry. The exact staged readme and changelog were inspected: headings, links and WordPress.org readme syntax remain valid; no task ID, governance term, Premium automation/AI, FX conversion or publication claim appears in the new public notes. The translation template's historical generator header still identifies the catalog generation version `1.3.0`; it is not a current product version identity.

Final public `changelog.txt` entry:

```text
= 1.4.0 (Sep 27, 2026) =

* Added Saved Customer Views and retention and attention quick views for reusable operational customer filters.
* Added explainable RFM facts and filters, with monetary values withheld when a customer's order currencies cannot be compared safely.
* Reworked the Customers list as an operations workspace and Customer Profile as an action-first view while preserving customer actions, notes, tasks, direct email, tags, static segments, exports, and optional integrations.
* Made recognized-order, refund, and order-reassignment monetary behavior consistent across customer lists, profiles, Overview, RFM, queries, and exports. Authoritative per-order currency tracking fails closed for genuine mixed or unknown currency without FX conversion; bounded recovery and backfill restore valid single-currency results after verification.
* Integrated with WordPress Personal Data Export and Erase. Bounded, resumable erasure retains one-way suppression receipts to prevent routine re-creation while preserving WooCommerce-owned orders and WordPress users.
* Expanded Diagnostics with data freshness, migration, and currency visibility; improved bounded large-data performance and introduced minimal Free extension contracts for future integrations.
* Resolved staged Plugin Check errors and hardened migrations, admin interfaces, HPOS and legacy order-storage regressions, and deterministic distribution packaging.
```

## Exact staged distribution

`bash scripts/stage-distribution.sh . <fresh-destination>` ran twice against the candidate working tree. Both staged trees had 62 files, matching per-file inventories and SHA-256 digests. The trusted `release_lib.deterministic_zip()` helper produced byte-identical packages named `yoohw-customer-intelligence-1.4.0.zip`.

| Artifact | SHA-256 or size |
| --- | --- |
| Product tree digest (`release_lib.tree_digest`) | `2609a2f3e7e8e3feaecdebb68bcaf4bbcc154fb0ee360494f1d90d158c489c3b` |
| ZIP SHA-256 | `6391005b1271f8302ce5a7387b8c329e4bfe42fd135e6d6e294ad0a44669ad19` |
| ZIP size | 1,294,098 bytes |

Both staged trees reported version `1.4.0`; `changelog_notes()` extracted the dated readme notes. The staging helper's allowlist and forbidden-artifact checks found no `.git`, `.github`, tests, docs, scripts, Composer metadata, archives, logs, symlinks or other development-only material in the payload.

## Validation

Local host: macOS arm64, PHP 8.4.26, Python 3.12.6, Node 26.8.1, MySQL 8.4.0, WP-CLI 2.9.0. The disposable sites used WordPress 6.9.7 and WooCommerce 10.8.0. Plugin Check was 2.1.0. No existing WordPress site, shared database or private customer data was used.

| Check | Result |
| --- | --- |
| `python3 .github/scripts/required-gate.py --self-test` | PASS, 2 tests |
| `python3 tests/release-contract-tests.py` | PASS, `release-contracts-ok` |
| Tracked PHP syntax on PHP 8.4, JavaScript syntax, Python compilation | PASS |
| `python3 scripts/test-isolated.py --mysql-bin <owned MySQL 8.4 bin> --php <PHP 8.4> --inputs <checksum-verified cache> --mode both` | PASS: 224 tests/4,574 assertions in each HPOS mode; 68 rejection controls per mode, benchmark smoke and unchanged unrelated synthetic sentinel |
| Staged Plugin Check, `--mode=new --format=csv --ignore-warnings` | PASS: 0 errors |
| Staged Plugin Check, full scan | 842 warnings, the same count and warning-code distribution as the accepted CIT-70 scan; no new category |

Fresh install: the exact staged plugin activated on a new site as `1.4.0`, installed schema `0.2.4` and 12 owned tables, and synchronized a synthetic completed USD order into a customer and order fact. A note, task, tag, static segment and both memberships were created. Bounded admin rendering verified Customers operations workspace and attention links, Profile action-first sections and RFM explanation, Saved Views, Overview and Diagnostics without a plugin fatal. A second EUR order for the same synthetic customer produced `money_state=mixed` and `money_is_comparable=false`; combined monetary output stayed unavailable.

Real upgrade: a separate new site first activated the actual repository tag `1.3.0@fed2c8ec4010d01a24a3d69bbb6d9929b2d0ccc1`, installed schema `0.2.1` and 11 tables, and created a synthetic completed USD WooCommerce order, customer, fact, note, order-linked task, tag, static segment and memberships. After deactivation, overlay with the exact staged `1.4.0` payload and reactivation, WordPress reported `1.4.0`; schema reached `0.2.4`/12 tables without Reset. The original order/customer and all listed owned relationships survived, with one order fact and no duplicate customer. Initially currency was unknown; ten bounded migration batches completed `identity_normalization_v2`, `commerce_facts_v2` and `commerce_currency_v3` and restored `comparable`/USD with $123.45 total. A current-schema legacy-currency replay registered a pending backfill and cron, withheld comparability, then reconverged in four batches. After upgrade, one Saved View was created. WordPress privacy exporter and eraser were registered; a separate synthetic subject exported two owned items, erased to completion with one suppression receipt, blocked routine resync and retained its WooCommerce order.

The real 1.3.0 tag has no Saved Views, privacy eraser/exporter or Reset Guard records. Their post-upgrade function was checked on the upgraded site; pre-upgrade records of those types were not invented. Local fresh-install UI verification was bounded rendered-HTML/API smoke rather than browser visual QA. The exact-candidate CI integration suite covers HPOS and legacy storage and the broader UI, privacy, currency and Reset contracts. These checks do not certify every declared WordPress, WooCommerce or PHP version.

Protected Prepare and Publish workflows were **not dispatched**. No numeric `1.4.0` tag, GitHub Release, WordPress.org SVN write or production deployment was created. After exact-head Technical Review, ChatGPT Acceptance and Human merge, the next release operation requires a **separate explicit Human command** to run `Prepare Customer Intelligence WordPress.org Release Candidate` with `candidate_sha=<exact protected-main SHA>` and `version=1.4.0`.
