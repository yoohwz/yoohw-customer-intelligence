# Test data safety contract

Never execute integration tests against an existing site, shared DB, customer export
or production/staging credentials. Source inspection does not authorize data mutation.
The supported runner is `python3 scripts/test-isolated.py --mysql-bin <MySQL-8-bin-dir>`
after `composer install --no-plugins --no-scripts`. PHP 8.4 with mysqli/posix/mbstring/XML,
Python 3.9+ and MySQL 8 are needed. `mysql` and `mysqld` must be in that directory (or pass `--mysqld <server-path>`).
Run as a regular user. `--mode yes|no|both` chooses storage; default runs both. Optional
`--inputs <archive-cache>` still verifies all SHA-256 checksums before extraction.

## Ownership and rejection

The runner creates a private random temporary directory, initializes a new MySQL data
directory without reading system defaults, disables TCP/MySQL X and uses only its own
Unix socket. It creates a random DB/user with privileges restricted to that DB and no
grant option, plus a random ownership token inside that DB. Root is used only by the
provisioner on its new server, never passed to WordPress. Ephemeral `environment.json`
contains disposable credentials, not task state; it is removed with the owned directory.

Both the bootstrap and direct integration source entrypoint call `tests/environment.php`
before WordPress, WooCommerce, Composer or supplied PHP configuration executes. The
canonical WordPress test config repeats that check in the install subprocess. The guard
requires a private owned real directory, matching token, exact dependency/config paths,
explicit HPOS mode, matching DB constants, scoped live SQL grants and the DB ownership
row. A suffix/flag alone never authorizes tests. This prevents accidental configuration
reuse; it is not a sandbox against malicious code running as the same OS user. Untrusted
PR code belongs only on disposable GitHub runners with read-only tokens and no secrets.

A pluggable `wp_mail` replacement is installed before fixture/plugin hooks and counts
attempts without storing recipients or bodies. The PHPUnit process also disables PHP
`mail`. The WordPress HTTP API is blocked before plugin hooks. No browser is needed; if browser QA is later admitted, use a fresh disposable
profile, synthetic data and persisted-state checks, never an existing signed-in profile.

## Evidence and cleanup

Every integration invocation executes positive and negative controls: missing/false/
mismatched root/token/paths/mode, wrong DB ownership, modified config, excessive grants
and predefined DB mismatch. Synthetic bootstrap files mark any early execution; a
separate synthetic DB sentinel is checked after each storage run. No customer data is
used to test rejection. The suite verifies actual HPOS state, and rejects skipped,
risky, empty, failed or errored test runs. A mail probe executes in the plugin hook.

The runner terminates only its own server process and removes only its own temporary
directory, including credentials, database, dependencies and reports, on success or
exception/SIGTERM. SIGKILL or host loss cannot guarantee cleanup: identify the exact
owned process/directory from that invocation before manual removal; never wildcard-clean
other test resources. The PR records outcomes, not credentials or PII.

## Reproducibility and support

Archive URLs and SHA-256 pins in the runner select WordPress 6.9, its 6.9.0 development
test library and WooCommerce 10.8.0. Composer's existing lock requires PHP 8.4 (including
Doctrine Instantiator 2.1.0); integration runs on PHP 8.4. PHP 7.4 is a separate syntax
check of tracked PHP, not installed-runtime coverage. This small test set does not
certify every declared WordPress/WooCommerce/PHP combination or change the plugin's
support contract. Action revisions are pinned; runner OS/MySQL/PHP patch provisioning
remains provider-managed and actual versions belong in CI logs.

All PRs, including drafts and docs/test/helper/workflow changes, run the full checks.
`YCI Required CI` accepts only the full classification and success from both syntax and
the complete integration matrix. Its self-tests cover missing, failed, cancelled,
skipped and unknown results. There are no path-based runtime exemptions, privileged
PR-target execution, release jobs, package uploads or approval-comment parsers.
