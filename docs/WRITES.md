# Everything Earlyward can write

The plugin is read-only by default. This is the complete list of writes. If code writes something
that is not on this list, that is a bug; please report it.

## Automatic (no confirmation needed)

| What | Where | Why | Removed on uninstall |
|---|---|---|---|
| Last scan result, the set of open alerts (per environment: web, command line), the healthy homepage baseline (A1) and the last measurements (A1 signals, A3 log sizes) | option `earlyward_state`, not autoloaded, capped at 500 findings | Show findings; email only when the set changes; compare with a healthy state; measure log growth | Yes |
| Scan lock | transient `earlyward_scan_lock` (10 minutes) | Stop two scans overlapping | Yes |
| Scheduled scan | WP-Cron event `earlyward_scan`, hourly; re-added on admin pages if something removed it | Run scans off the front end | Yes (also removed on deactivation) |
| Alert email | `wp_mail()` to the site's admin email address | Only when the set of warning/critical findings changes; info findings never email | n/a |

**Admin screen (Tools → Earlyward).** Viewing it writes nothing. Two buttons write, both need the `manage_options` capability and a nonce, and both only do what the CLI already does:

- *Scan now* runs the scheduled job once (same writes as a scheduled scan, same email rule).
- *Set baseline* clears the A1 baseline and records a new one from a scan right now, if the homepage looks healthy. It does nothing unless the "I changed the site on purpose" box is ticked.

`wp earlyward scan` writes nothing. `wp earlyward run` writes the same as a scheduled scan. `wp earlyward baseline --reset` clears the A1 baseline.

## Network

Each scan makes two requests to the site's own address (`/` and `/wp-json/`, with a cache-busting query string) for check A1. Nothing else is contacted.

## Only after you confirm (none implemented yet)

| What | Effect | How to undo |
|---|---|---|
| Hardening toggles | Settings applied through WordPress filters (file editor off, XML-RPC off, user enumeration blocked, security headers) | Switch the toggle off |
| Account actions (v1.0) | Demote a suspicious admin to subscriber; revoke their sessions and application passwords. The original role is saved first. | "Restore" button using the saved copy |

Nothing in Earlyward deletes or edits theme, plugin, core or upload files.
