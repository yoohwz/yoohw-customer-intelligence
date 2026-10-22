# CIT-108 Free 1.4.4 release-candidate preparation

Prepared 2026-10-22 (Asia/Ho_Chi_Minh). [Issue #108](https://github.com/yoohwz/yoohw-customer-intelligence/issues/108), Controlled. Branch: `codex/cit-108-release-candidate`.

## Outcome: certification blocked

This is an incomplete draft release candidate. The exact 1.4.4 package has **0 ERROR / 2 WARNING** records under accepted Plugin Check 2.1.0 default checks, so it fails the Issue's mandatory zero-error/zero-warning condition. Issue #108 requires stopping and escalating unexpected scanner findings instead of broadening this release task. No runtime or PHPCS annotation correction has been made. Do not submit this candidate for Acceptance, merge, Prepare or publication.

Admitted and current protected main/base: `82941930566484b14e3fefb2301083dec5e9fca2`. Its only source change since public 1.4.3 is the merged CIT-106 / PR #107. The versioned payload changes only the main PHP version identity, readme release notes and full changelog. Exact pushed head, native CI and current next owner belong in the owning PR evidence; they are not invented through a self-referential source commit.

## Public identity and deterministic distribution

- Main-file Plugin Name and readme title remain `Customer Intelligence for WooCommerce`.
- Main PHP `Version`, `YOOHW_COS_VERSION` and readme Stable tag are `1.4.4`.
- `YOOHW_COS_DB_VERSION` remains `0.2.4`; no schema migration.
- Readme Changelog contains exactly one heading: `= 1.4.4 =`, dated Oct 22, 2026.
- Full changelog prepends `= 1.4.4 (Oct 22, 2026) =`; every byte from the existing 1.4.3 heading down is unchanged against the admitted base.
- Four public notes cover the merged Overview/Customer Details layout, readable task/purchase details and monetary explanations, existing task reopen as Undo, and inline CRM email heading counts. No new storage, FX, Premium or AI behavior is promised.

Two fresh canonical stages and the accepted deterministic ZIP encoder produced identical inventories, tree hashes and ZIP bytes:

| Item | Value |
| --- | --- |
| Product files | 65 |
| Product tree SHA-256 | `508aaf9645889dfd874abbaf12cf54d817951a4dea82263d3dbb43c82c4cedae` |
| `yoohw-customer-intelligence-1.4.4.zip` SHA-256 | `7faaa22b6f8539d97bbf548d51cb611e413d825eed27d509b128c0e04af5872e` |
| Actual public 1.4.3 ZIP SHA-256 | `a6e274753f3016847e3d68e7d0360062c8926d57baaad7bb129884596779d2ab` |
| Actual public 1.4.3 product tree SHA-256 | `da4238e2fa53bcb814879ebd15305f4f22bad6a80a40594df680bd2ec2f60fe4` |
| Plugin Check 2.1.0 ZIP SHA-256 | `6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4` |
| WP-CLI 2.12.0 PHAR SHA-256 | `ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c` |

[Exact staged inventory](cit-108/staged-inventory.json) contains every product file, byte size and SHA-256. Staging excludes development, dependency, test, documentation, private and nested archive artifacts. The two ZIPs were byte-compared. The public archive was downloaded from `https://downloads.wordpress.org/plugin/yoohw-customer-intelligence.1.4.3.zip`; its extracted tree matches the previously verified public release tree. Read-only remote inspection reconfirmed annotated tag object `3d5ae70fd4baf89af296e5617577ecc923ce6f95`, target commit `e5046d261c1de125061a18743a3ccf8794da38b4`. No public 1.4.3 identity was mutated.

## Scanner blocker

Command in a newly owned disposable WordPress/WooCommerce environment, against the extracted exact deterministic 1.4.4 ZIP:

```text
php -d disable_functions=mail wp-cli.phar --require=<owned guard and offline preload> plugin check yoohw-customer-intelligence --mode=new --format=csv
```

All default checks remain enabled. No excludes, blanket ignores or new annotations were added. The actual scanner process exits **0**, but parsing its diagnostic records gives **0 ERROR / 2 WARNING**. This is a failed certification, regardless of that process status. [Raw scanner records](cit-108/plugin-check.csv):

| File | Line | Type | Code |
| --- | ---: | --- | --- |
| `includes/class-yoohw-cos-overview.php` | 259 | WARNING | `PluginCheck.Security.DirectDB.UnescapedDBParameter` |
| `includes/class-yoohw-cos-overview.php` | 269 | WARNING | `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` |

Both diagnostics refer to `$view_where` in `get_priority_tasks()`. CIT-106 added the `overdue` and `due_soon` branches; they assemble fixed SQL clauses using prepared timestamps, then interpolate the resulting clause into an outer prepared query. That code is inherited unchanged from the admitted main. The diagnostics alone do not establish SQL injection or a material runtime defect. The smallest unresolved decision is authorization for a bounded correction or an independently admitted fix, followed by regenerating and recertifying the package. This candidate does not waive the zero-warning requirement.

A paired default scan in the same owned environment then replaced the candidate directory with the actual public 1.4.3 archive. That scan reports **0 ERROR / 0 WARNING** and `Success: Checks complete. No errors found.` The versioned candidate scan reproduced the exact same two warnings before that replacement. The public baseline remains comparison evidence only.

The scanner fixture first activated the exact 1.4.4 ZIP and asserted runtime version `1.4.4` and installed DB version `0.2.4`. This is a narrow activation check, not completion of the full fresh-install checklist. WP-CLI emits its pre-defined-ABSPATH advisory because the ownership guard defines the exact private WordPress root before bootstrap; the scanner still runs and reports the above concrete file diagnostics. An initial scanner harness invocation did not register the `plugin check` command because Plugin Check was not active; that invocation is not certification evidence. The corrected invocation loaded the actual scanner plugin and parsed its records.

## Validation completed and remaining

Local runtime: PHP 8.4.26, MySQL 8.0.35, Node 24.19.0, Python 3.12.6, WordPress 6.9, WooCommerce 10.8.0, PHPUnit 9.6.36. Composer installed the unchanged lock using `--no-plugins --no-scripts`. The system Node executable has a missing `libsimdjson.33.dylib`; the bundled Node 24.19.0 executed the JavaScript checks and canonical runner without changing the host installation. Download verification used the existing Homebrew CA bundle after the default Python CA configuration rejected TLS; certificate verification was not disabled.

| Check | Result |
| --- | --- |
| `python3 .github/scripts/required-gate.py --self-test` | PASS, 2 tests |
| `python3 tests/release-contract-tests.py` | PASS, `release-contracts-ok` |
| Tracked PHP/JS/Python syntax | PASS, 77 / 4 / 9 files |
| `git diff --check` | PASS |
| Two canonical stages and deterministic ZIP comparison | PASS |
| Exact history retention | PASS |
| Exact package Plugin Check default scan | BLOCKED, 0 ERROR / 2 WARNING |
| Canonical full HPOS=yes | PASS, 293 tests / 6,584 assertions |
| Canonical full HPOS=no | PASS, 293 tests / 6,584 assertions |
| Entrypoint admission/rejection and grant-option controls | PASS, 144 controls before both storage runs |
| Canonical benchmark smokes | PASS, both storage modes |
| Unrelated DB sentinel / owned cleanup | PASS |

Canonical integration command:

```text
python3 scripts/test-isolated.py --mysql-bin <MySQL 8.0.35 bin> --php <PHP 8.4.26> --inputs <checksum-verified archive cache> --mode both
```

The unchanged integration suite covers currency/availability, native clean CSV transport, readable Lifetime money, exactly-one First-time cohorts, HTML/plain CRM email links/entities, privacy, Reset, migrations, Saved Views, task guards, notifications and native asset-loader contracts. These isolated tests do not replace versioned public-upgrade or native browser certification.

The five CIT asset enqueues still use `YOOHW_COS_VERSION`; none of their enqueue source files changed. The intended candidate URLs end in `ver=1.4.4` for `assets/css/admin.css`, `assets/js/admin.js`, `assets/js/notice-preferences.js`, `assets/css/order-admin.css` and `assets/js/order-admin.js`. WooCommerce dependencies retain `WC_VERSION`. No native browser-emitted 1.4.4 URLs have been observed in this blocked run; do not label intended URLs as observed.

Certification stopped at the scanner blocker. **Not completed:** persisted real public 1.4.3 to 1.4.4 upgrade scenarios; complete fresh-install feature checklist; authenticated responsive browser matrix and 119/126 snapshot comparison; native task Complete/Undo and new layout/state transport checks; exact candidate fresh independent Technical Review; ChatGPT Acceptance. Prior CIT-106 responsive/acceptance evidence is context only. No shared or existing Local site was used as a substitute.

## Safety and handoff

All executed fixtures used newly owned private roots, isolated socket-only MySQL, random scoped DB/user/token, canonical ownership/path/grant admission, early mail interception, disabled PHP mail and blocked WordPress HTTP. No existing site/database/customer data or production credentials were used. Sentinels and owned cleanup outcomes are recorded below and in PR evidence.

The completed canonical both-HPOS run and paired-scanner run independently verified their unrelated synthetic database sentinels, stopped only their owned MySQL processes and removed their own temporary roots. The failed initial scanner harness and first warning-detecting scanner invocation also removed their exact owned roots; post-run checks confirmed removal. Downloaded synthetic source archives, staged packages and non-secret local logs remain outside the repository under `/private/tmp/cit108-validation` for recovery. The paired scanner wrapper additionally completed its owned large synthetic benchmark; it does not substitute for either canonical smoke. No browser/server was launched for layout QA after the blocker.

No Prepare/Publish dispatch, Git tag, GitHub Release, SVN write, merge, deployment or release-control change occurred. Protected main still requires strict `YCI Required CI`, enforced admins and disallowed force-push/deletion. The draft PR preserves bounded work and the scanner blocker; next owner is ChatGPT/Human for the minimal scope decision, then Codex resumes the same task and repeats affected certification.
