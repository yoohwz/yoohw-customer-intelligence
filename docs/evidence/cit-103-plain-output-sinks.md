# CIT-103 plain CRM body output sink

Prepared 2026-10-04 from admitted protected main `1e4dd12261f065394dd755cd7b3c367eb7379dd2`. Controlled; target main. Scope: [CIT-103](https://github.com/yoohwz/yoohw-customer-intelligence/issues/103), under its [approved Plan Review](https://github.com/yoohwz/yoohw-customer-intelligence/issues/103#issuecomment-5977670527). Exact committed head/base, PR, native CI and fresh independent review bindings belong in the PR evidence after committing; recording this file's own commit SHA would change the candidate.

## Change and output context

The baseline default Plugin Check reports 64 `WordPress.Security.EscapeOutput.OutputNotEscaped` errors at the two CRM task/digest text/plain templates. Their accepted plain serializers are not recognized by the HTML-oriented static output check. This is a scanner/output-context compatibility correction, not a demonstrated runtime injection vulnerability.

The CRM base adds one `output_plain_body()` sink accepting an already serialized body string. Dynamic pieces in both plain templates still pass through the existing `plain_text()` / `plain_url()` calls before entering this sink; concatenation, conditional labels, literal separators and newlines remain equivalent. Greeting output uses the same serialized format/value with `sprintf()` into the body sink instead of direct `printf()`. The sink performs no second entity decode or HTML escaping. Its only new suppression is one inline annotation for `WordPress.Security.EscapeOutput.OutputNotEscaped` on its audited echo, with a body-only context justification. It is never used for headers, subject, addresses or transport settings.

`plain_text()` and `plain_url()` semantics are unchanged: decode entities and strip markup for text; decode entities, reject literal/entity/percent-encoded CR/LF and permit HTTP(S) for action URLs. Literal query separators stay literal. HTML templates, URL producers, recipient/timing/ledger/retry/transport behavior, metadata, schema and protected workflows are unchanged. Existing header-injection and HTML-destination parity tests remain active.

The focused unsafe URL regression now also exercises numeric CR/LF entities (`&#13;&#10;`) and uppercase percent-encoded CR/LF (`%0D%0A`) in both notification and digest templates.

## Exact staged scanner verification

Canonical staging was invoked twice on fresh destinations; both 65-file inventories and per-file SHA-256 values match. Deterministic ZIP bytes match. Product header/runtime/readme identity remains the admitted **1.4.2**; CIT-103 does not bump or republish it. Installed DB version remains 0.2.4.

| Artifact | SHA-256 |
| --- | --- |
| Exact candidate product tree | `3da0c3123f57187c889e4ad0ed7c69af08a8172a218b90c794ac58b2fe166def` |
| Local deterministic verification ZIP | `dfeeee5f732abd8d9b0cd8d36daade83899d62d3f395f4536aab5faf6cae7ba3` |
| Plugin Check 2.1.0 archive | `6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4` |
| WP-CLI 2.12.0 archive | `ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c` |

Both the exact admitted base payload and candidate were scanned on the same newly provisioned disposable WordPress/WooCommerce site, in fresh WP-CLI processes, using:

```text
wp plugin check yoohw-customer-intelligence --mode=new --format=csv --ignore-warnings
wp plugin check yoohw-customer-intelligence --mode=new --format=csv
```

Default checks remain enabled; no ignored error codes, excluded template paths, whole-file suppression or project-only scanner configuration was supplied. Records were parsed by file/type/code/message; command exit status alone was not treated as proof. The baseline scan reproduces **64 ERROR / 907 WARNING** records. Candidate error-only output is `Success: Checks complete. No errors found.`; full output has **0 ERROR / 907 WARNING** records. There are zero `OutputNotEscaped` records in either plain template or the new sink.

Warning multisets match exactly by file, severity, code and message, ignoring shifted source line/column coordinates. No warning was added or removed. The changed output boundary adds no material scanner finding. The unchanged warnings are classified below; this bounded correction is not a general warning cleanup or certification of all legacy warnings.

| Existing warning code | Count |
| --- | --- |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` | 251 |
| `WordPress.DB.DirectDatabaseQuery.NoCaching` | 243 |
| `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | 2 |
| `WordPress.DB.SlowDBQuery.slow_db_query_meta_key` | 18 |
| `WordPress.Security.NonceVerification.Missing` | 17 |
| `WordPress.Security.NonceVerification.Recommended` | 281 |
| `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` | 4 |
| `WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber` | 4 |
| `WordPress.PHP.DevelopmentFunctions.error_log_error_log` | 2 |
| `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` | 13 |
| `WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare` | 1 |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound` | 11 |
| `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 1 |
| `WordPress.Security.ValidatedSanitizedInput.InputNotValidated` | 3 |
| `WordPress.Security.ValidatedSanitizedInput.MissingUnslash` | 4 |
| `WordPress.DB.DirectDatabaseQuery.SchemaChange` | 1 |
| `mismatched_plugin_name` | 1 |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound` | 50 |

Direct-query/cache/slow-query/schema warnings concern pre-existing persistence paths; nonce/input warnings concern pre-existing request reads; prepared-SQL warnings concern pre-existing queries; prefix warnings concern existing globals/hooks; error-log warnings concern existing operational logging; name mismatch is existing public metadata. The changed sink/template path introduces no new member of these categories. CIT-102 retains responsibility for final versioned release warning disposition.

## Regression and safety checks

Tools: macOS arm64, PHP 8.4.18, MySQL 8.0.35, Python 3.12.6, Node 26.8.1, PHPUnit 9.6.36, WP-CLI 2.12.0, Plugin Check 2.1.0. WordPress 6.9 and WooCommerce 10.8.0 use canonical checksum-pinned archives. Composer installed the existing lock with `--no-plugins --no-scripts`.

| Check | Result |
| --- | --- |
| Required-gate self-test | PASS, 2 tests |
| Release-contract tests | PASS, `release-contracts-ok` |
| Tracked syntax | PASS: 77 PHP, 4 JavaScript, 9 Python files |
| HTML templates unchanged / `git diff --check` | PASS |
| Canonical isolated full HPOS=yes suite | PASS, 291 tests / 6,521 assertions |
| Canonical isolated full HPOS=no suite | PASS, 291 tests / 6,521 assertions |
| Ownership/rejection controls | PASS, 144 controls, including live grant-option rejection |
| Both benchmark smokes, sentinel and full runner cleanup | PASS |
| Paired Plugin Check site sentinel / owned cleanup | PASS |

The full suite executes all eight CRM task email states plus reassignment handoff through public `get_content_plain()` before WooCommerce transport normalization. Assertions cover entity/markup-free copy, readable apostrophes, exact parsed page/task IDs with empty fragment, distinct digest IDs/task-list URL, unsafe scheme and CR/LF rejection, body text unable to create headers and HTML action destination parity. Existing notification capability, Reset, privacy, ledger, retry and delivery paths remain covered alongside the complete plugin integration suite.

Canonical integration runs and paired scanner verification used separate newly initialized socket-only MySQL instances with private random scoped credentials and ownership rows. WordPress never received provisioning root credentials. Mail was intercepted before plugin hooks, native PHP mail disabled, WordPress external HTTP blocked and WP-Cron disabled for the scanner site. Unrelated synthetic sentinels were checked. Scanner credentials/database/process/files and its read-only base comparison checkout were removed. No existing Local installation, production credentials or real customer data were used. Raw temporary scanner diagnostics remain outside the product checkout; the distribution contains no fixtures or credentials.

## Limits and handback

Local PHP runtime checks cover PHP 8.4 / WordPress 6.9 / WooCommerce 10.8.0; PHP 7.4 syntax is provided by exact-head native CI. No browser QA is needed for this text/plain sink correction. Exact-head native `YCI Required CI` and a fresh source-read-only Controlled Technical Review are required before ChatGPT Acceptance and are recorded in the PR. This evidence does not authorize merge or release.

CIT-102 changes remain isolated and preserved in their original uncommitted worktree. After CIT-103 Acceptance and separate Human merge, resume `Run CIT-102` from the resulting protected main, retain the approved 1.4.3 metadata/asset/latest-only-readme rules, regenerate its product/package hashes and repeat Plugin Check plus all remaining version-coupled certification. Pre-CIT-103 1.4.3 digests are superseded by changed product bytes. No Prepare/Publish dispatch, tag, GitHub Release, WordPress.org SVN write, shared-site deployment or merge was performed.
