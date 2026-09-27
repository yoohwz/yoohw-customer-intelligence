# CIT-63 staged Plugin Check correction

Admitted base: protected `main@b050fa03aecce7cdc23acb66a165274ae9af7bf8` (post-CIT-67). The exact candidate commit is the head SHA of the CIT-63 PR; resolve it with `git rev-parse HEAD` after checking out that PR. The PR evidence records the literal head SHA because a commit cannot contain its own hash in a tracked file. No release metadata or publication action is part of this task.

## Reproduction and staged boundary

Both the baseline and corrected source were staged from their repository root with `bash scripts/stage-distribution.sh . <fresh-destination>`. The baseline stage contained 62 files. Two independent corrected stages contained 62 files each and compared byte-for-byte; the sorted per-file SHA-256 manifest digest was `a746257e0f02b67edffb56efbd0afa0c2ff71442107603306437eb19ea2da1f9`. Plugin Check scanned only the staged plugin copied into a newly installed, disposable WordPress site. The staged plugin activated and loaded with WooCommerce active.

Scan environment: WordPress 6.9.7, WooCommerce 10.9.4, PHP 8.4.21, MySQL 8.4.0, Plugin Check 2.1.0. Plugin Check 2.1.0 was the current WordPress.org stable version at the time of scanning. The CLI command used `--slug=yoohw-customer-intelligence --mode=new --format=csv`, once with `--ignore-warnings` and once without it. PHP 8.4 deprecation output from the installed WP-CLI PHAR was excluded from finding counts. Scan result rows, not WP-CLI process status, supply the counts.

| Finding | Fresh admitted base | Corrected stage |
| --- | ---: | ---: |
| `WordPress.DB.PreparedSQL.NotPrepared` | 15 | 0 |
| `WordPress.WP.I18n.NonSingularStringLiteralDomain` | 12 | 0 |
| `WordPress.WP.I18n.MissingTranslatorsComment` | 4 | 0 |
| `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 4 | 0 |
| `WordPress.Security.EscapeOutput.OutputNotEscaped` | 3 | 0 |
| `WordPress.WP.I18n.NonSingularStringLiteralText` | 2 | 0 |
| **Total errors** | **40** | **0** |

The fresh error list affected attention, privacy erasure/export, schema installation, Customer Profile and admin menu. It exactly reproduces CIT-62's historical 40 error count and classes on a newer admitted base; no historical error disappeared or new error appeared. CIT-62's 833 warnings were historical: the refreshed base had 839 warnings, six more. The corrected stage had 842 warnings, three more than the refreshed base. The different WordPress/WooCommerce versions and admitted source prevent treating the historical warning delta as a like-for-like regression.

## Corrections

- Translation calls now use literal text domains and literal, extractable privacy export field/group labels. Saved View keys use the same existing display words and have explicit translation entries. The export field map is cached per locale for the request, avoiding repeated translation of every label for every exported field. Translator comments describe each formatted placeholder.
- Admin batch counts are normalized to nonnegative integers and escaped at output. This preserves the displayed numeric result.
- The optional `wp_user_id` privacy predicates and fixed privacy projection now use separate, literal SQL templates with `%i`, `%s` and `%d` placeholders. They retain email, alias, limit and ordering semantics.
- The schema ensure helper accepts only statements prepared with `%i` at its fixed-schema callers. The privacy exporter constructs projection/join/order fragments only from its private fixed category map and prepares all subject values. Narrow, line-local PHPCS annotations document these two scanner data-flow limits; no category is disabled.

The isolated HPOS=yes/no suite includes privacy exporter/eraser and suppression, monetary migration, Saved Views/RFM/attention, Customer Profile actions, Reset Guard and WooCommerce order-store flows. The Saved View exporter test now asserts the group and fixed field labels, and a multi-item export bounds field-label translation work. No source behavior was intentionally expanded.

## Warning triage

| Warning category | Base | Candidate | Disposition |
| --- | ---: | ---: | --- |
| Nonce recommended | 271 | 271 | Mostly read-only admin list filters, state rendering and redirects. Write handlers use action-specific WordPress nonce checks and capabilities; WooCommerce order saves verify core edit nonces. No nonce check was relaxed. |
| Nonce missing | 17 | 17 | Scanner sees POST request inspection in a read-only filter redirect and upstream WooCommerce request readers, plus verified order-save paths. The filter redirect only rebuilds sanitized query state; order writes pass `verify_order_save_request()`. |
| Direct DB query / no cache | 233 / 225 | 236 / 228 | Custom-table schema, transactional writes, paged privacy reads and current-state reads require direct SQL. The new literal privacy branches add three call sites to each count, not extra runtime queries. Caching these writes or subject-bound reads would risk stale state. |
| Unprefixed template variables / hook names | 31 / 11 | 31 / 11 | WooCommerce email template variables and WooCommerce hook contracts; renaming them would break interoperability. |
| Slow `meta_key` / `meta_query` | 18 / 2 | 18 / 2 | Bounded WooCommerce/extension metadata lookups. Existing indexed order/customer facts remain the main query path; the scanner reports API usage without a measured release regression. |
| Input not sanitized / missing unslash / not validated | 8 / 4 / 3 | 8 / 4 / 3 | Bulk IDs are shape checked, bounded, then unslashed and passed through `absint`; scoring input is unslashed before policy validation. Reset epoch values are read in guarded handlers to preserve the exact submission token, not trusted as data fields. |
| SQL replacements wrong / interpolated / unfinished prepare / unescaped parameter | 6 / 5 / 1 / 1 | 4 / 4 / 1 / 1 | Remaining variable placeholder lists are generated from bounded integer IDs and supplied to `prepare()`; the order-search predicate is assembled from fixed fragments, with values in placeholders. The privacy exporter category map is fixed and subject values are prepared. These are scanner data-flow limits, not raw input interpolation. |
| Schema change / operational `error_log` / plugin name mismatch | 1 / 1 / 1 | 1 / 1 / 1 | Schema migration is intentional; the Reset Guard log has a fixed message without customer data; the readme marketing name differs from the plugin header but does not alter security or data handling. Revisit the display-name warning during the final release-readiness audit if WordPress.org policy requires exact naming. |
| **Total warnings** | **839** | **842** | No material security, data, nonce, input or SQL defect was identified among the remaining warnings. |

## Validation and limits

Required exact-head CI, the two-mode isolated suite, syntax, staged-package comparison, admin smoke, exact PR SHA and independent Technical Review outcomes are recorded in the PR once complete. This local Plugin Check run covers static WP-CLI checks in `--mode=new` on WordPress 6.9.7 and WooCommerce 10.9.4. It does not certify every declared compatibility version, all admin browser paths or the later 1.4.0 release candidate. `Version:`, `YOOHW_COS_VERSION` and `Stable tag:` remain 1.3.0. No tag, release, SVN publication or deployment was created.
