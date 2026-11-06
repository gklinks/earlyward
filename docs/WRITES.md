# Everything Wallwarden can write

The plugin is read-only by default. This is the complete list of writes. If code writes something
that is not on this list, that is a bug; please report it.

## Automatic (no confirmation needed)

| What | Where | Why | Removed on uninstall |
|---|---|---|---|
| Plugin settings | option `wallwarden_settings` (not autoloaded) | Your choices | Yes |
| Last scan results and fingerprints | option `wallwarden_state` (not autoloaded) | Show findings; email only on change | Yes |
| Scan cursor | transient `wallwarden_cursor` | Resume a long scan across cron runs | Yes |
| Scheduled scan | one WP-Cron event `wallwarden_scan` | Run scans off the front end | Yes |
| Alert email | `wp_mail()` to the site admin address | Only when the set of findings changes | n/a |

## Only after you confirm (none implemented yet)

| What | Effect | How to undo |
|---|---|---|
| Hardening toggles | Settings applied through WordPress filters (file editor off, XML-RPC off, user enumeration blocked, security headers) | Switch the toggle off |
| Account actions (v1.0) | Demote a suspicious admin to subscriber; revoke their sessions and application passwords. The original role is saved first. | "Restore" button using the saved copy |

Nothing in Wallwarden deletes or edits theme, plugin, core or upload files.
