# Notification recipient policy

Internal task events and digests select an existing WordPress account with a valid
current email and effective `manage_woocommerce` access in the selected current site.
The actor/cron user does not supply the recipient's authority. WordPress effective
capability and super-admin semantics remain intact; assignment eligibility is unchanged.

Each public event/digest trigger starts a new recipient context and clears it on exit,
including rejection and exceptions. The shared send path checks enabled state and
recipient access before rendering, then wraps only that email's WooCommerce transport
callback to re-evaluate immediately before transport, after content and parameter hooks.
A changed site, destination or selected recipient rejects that attempt instead of
sending content rendered for stale context. A later ordinary attempt can select fresh
state. This is not a global `wp_mail` gate or a restriction on trusted third-party code
that replaces the callback or rewrites final recipients.

Overdue escalation remains disabled by default. When enabled, its dynamic assignee
requires staff access, while its explicitly configured addresses have separate
administrative-config authority. An empty setting uses the current site's `admin_email`.
Invalid addresses are discarded and addresses are deduplicated case-insensitively.
A denied/missing assignee does not prevent valid configured delivery. Removing staff
access does not edit configured address lists: a revoked account explicitly listed
there still receives escalation under that setting. Ordinary staff emails have no
administrative-address fallback. Context changes during rendering reject the current
attempt; configured delivery can proceed on the next stable ordinary attempt.

Disabled messages, empty permitted destination sets and failed transports return false.
Existing workers release their owned claim on failure and mark it sent only on true;
existing bounded continuations, chunk boundaries and deduplication remain unchanged.
There is no new queue, replay or exactly-once/inbox-delivery guarantee.

Manual customer messages retain their separate selected-customer path. A guest customer
needs no WordPress account or staff capability. The existing manager capability, nonce
and Reset selection checks still protect the request.

Tests observe synthetic recipients/content only in memory through an opt-in intercepted
transport fixture, then remove the observer. Positive cases render real pinned templates;
negative cases check absence of task payload and truthful worker ledger state. The
current-site tests exercise actual WordPress capability APIs in the single-site runtime
and controlled site-context invalidation. They are not full multisite deployment,
SMTP/inbox, provider compatibility or full supported-runtime matrix certification.
