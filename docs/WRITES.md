# Everything Wallwarden can write

The plugin is read-only by default. This is the complete list of writes. If code writes something
that is not on this list, that is a bug; please report it.

## Automatic (no confirmation needed)

| What | Where | Why | Removed on uninstall |
|---|---|---|---|
| Last scan result and the set of open alerts (per environment: web, command line) | option `wallwarden_state`, not autoloaded, capped at 500 findings | Show findings; email only when the set changes | Yes |
| Scan lock | transient `wallwarden_scan_lock` (10 minutes) | Stop two scans overlapping | Yes |
| Scheduled scan | WP-Cron event `wallwarden_scan`, hourly; re-added on admin pages if something removed it | Run scans off the front end | Yes (also removed on deactivation) |
| Alert email | `wp_mail()` to the site's admin email address | Only when the set of warning/critical findings changes; info findings never email | n/a |

`wp wallwarden scan` writes nothing. `wp wallwarden run` writes the same as a scheduled scan.

## Only after you confirm (none implemented yet)

| What | Effect | How to undo |
|---|---|---|
| Hardening toggles | Settings applied through WordPress filters (file editor off, XML-RPC off, user enumeration blocked, security headers) | Switch the toggle off |
| Account actions (v1.0) | Demote a suspicious admin to subscriber; revoke their sessions and application passwords. The original role is saved first. | "Restore" button using the saved copy |

Nothing in Wallwarden deletes or edits theme, plugin, core or upload files.
