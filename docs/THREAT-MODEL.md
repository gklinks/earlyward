# Threat model

Short and honest. This describes what Wallwarden defends, what it assumes, and how it could itself
be attacked.

## What we are trying to do

Give a site owner early, evidence-backed warning of compromise, broken hardening and runtime
failures, from inside WordPress, without making the site less safe or less available.

## Assets

- The site's availability (the plugin must never take a site down).
- The findings themselves: they reveal weaknesses and must not leak to non-administrators.
- The site's users and content (the plugin must not destroy them, even when asked to "fix" things).

## Attacker models

| Attacker | Can | Wallwarden's position |
|---|---|---|
| Anonymous visitor | Send any HTTP request | Must gain nothing: no public endpoints by default, nothing runs on front-end requests except reversible hardening filters. |
| Logged-in low-privilege user | Use wp-admin and REST as subscriber/author | Must not read findings, trigger scans or run actions. Capability `manage_options` (or `manage_network_options` on multisite) plus nonces on every action. |
| Code already running as the site's PHP user (malware) | Read and write every file and database row the site can | **Out of reach.** It can edit or delete Wallwarden, fake its results, or suppress its email. Wallwarden can only raise the cost of hiding and record evidence early. This is why the README says what it says. |
| Another site on the same Linux account | Same as above, for every site on the account | Same. Wallwarden warns that this arrangement exists (check B1); it cannot fix it. |

## How the plugin itself could be attacked, and the mitigation

| Risk | Mitigation |
|---|---|
| Crafted file or DB content stalls or crashes a scan, hiding everything after it | Every check has a time and size budget; reads are capped; each check is isolated in `try/catch`; a check that fails is reported as "could not check", never as "clean". |
| Path traversal through a user-supplied path | No user-supplied paths reach the filesystem layer. Paths come from WordPress constants and a fixed list; the walker refuses symlinks and anything outside the allowed roots. |
| Stored XSS through evidence excerpts (an attacker controls the file content we display) | All evidence is escaped on output and truncated; never rendered as HTML. |
| SQL injection | `$wpdb->prepare()` everywhere; no dynamic identifiers except the table prefix from WordPress. |
| CSRF on settings or account actions | Nonces plus capability checks; destructive actions need a second confirmation. |
| Information leak of findings | Findings are stored in a non-autoloaded option, shown only to administrators, and emailed only to the configured admin address. |
| Plugin used as a pivot for outbound requests | No outbound requests except loopback to `home_url()` and owner-approved checks to fixed hosts. |
| Malware tampering with the plugin to show "all clear" | Partly unsolvable from inside. Mitigations: the external probe script, email-on-change (a sudden silence after findings is itself a signal), and documenting that silence is not proof. |

## Non-goals

Blocking attacks, cleaning infections, and protecting a server that is already compromised.
