# Everything Earlyward can write

Scans are read-only. Changes happen only when an administrator confirms a fix. This is the complete list of writes. If code writes something
that is not on this list, that is a bug; please report it.

## Automatic (no confirmation needed)

| What | Where | Why | Removed on uninstall |
|---|---|---|---|
| Last scan result, the set of open alerts (per environment: web, command line), the healthy homepage baseline (A1) and the last measurements (A1 signals, A3 log sizes) | option `earlyward_state`, not autoloaded, capped at 500 findings | Show findings; email only when the set changes; compare with a healthy state; measure log growth | Yes |
| Fix log (only once a fix has been used) | option `earlyward_fix_log`, not autoloaded, last 50 entries | Show what was changed and let it be reversed by hand | Yes |
| Quarantine record (only once something was quarantined) | option `earlyward_quarantine`, not autoloaded, at most 200 items: folder name, and for each item its original place, stored name, sha256, size, time, user, check | List what is in quarantine and put it back | The option yes; the folder and its files stay, see below |
| Scan lock | transient `earlyward_scan_lock` (10 minutes) | Stop two scans overlapping | Yes |
| Scheduled scan | WP-Cron event `earlyward_scan`, hourly; re-added on admin pages if something removed it | Run scans off the front end | Yes (also removed on deactivation) |
| Alert email | `wp_mail()` to the site's admin email address | Only when the set of warning/critical findings changes; info findings never email | n/a |

**Admin screen (Tools → Earlyward).** Viewing it writes nothing. Two buttons write, both need the `manage_options` capability and a nonce, and both only do what the CLI already does:

- *Scan now* runs the scheduled job once (same writes as a scheduled scan, same email rule).
- *Set baseline* clears the A1 baseline and records a new one from a scan right now, if the homepage looks healthy. It does nothing unless the "I changed the site on purpose" box is ticked.

**Overview tab.** Writes nothing and contacts nobody. It shows what WordPress already holds (update information from WordPress's own last check, comment counts, users, the cron schedule). The one thing Earlyward adds to its existing stored state is a small record of when the set of warning or critical findings last changed (time, counts), so the tab can show it.

**Site Health** (Tools → Site Health) shows the stored findings as tests. It only reads the last stored scan: no scan, no request, no write.

`wp earlyward scan` writes nothing. `wp earlyward run` writes the same as a scheduled scan. `wp earlyward baseline --reset` clears the A1 baseline.

- The admin bar badge and the dashboard widget only read the stored scan result. They write nothing and start no scan.

## Network

Each scan makes two requests to the site's own address (`/` and `/wp-json/`, with a cache-busting query string) for check A1. Check B3 also sends a header-only request (HTTP HEAD, nothing is downloaded, redirects not followed) to the site's own address for each backup, dump or config copy it finds, at most 8 per scan, and none when there are no such files. Nothing else is contacted, with one opt-in exception below.

**Check E1 (known vulnerabilities), off unless you turn it on.** Define `EARLYWARD_WORDFENCE_KEY` in `wp-config.php` with a free Wordfence Intelligence API key and each scan may download the public vulnerability feed from `www.wordfence.com` (`GET /api/intelligence/v3/vulnerabilities/scanner`, at most every 12 hours; after a failure, every hour). The request carries the key and nothing about your site: no URL, no plugin list. Matching against your installed plugins, themes and WordPress version happens on your server. The reduced result (only records for software you have installed) is cached in the option `earlyward_vuln_cache`, not autoloaded, removed on uninstall. Without the constant, nothing is contacted and the check does nothing. Vulnerability data is Wordfence Intelligence (Copyright Defiant Inc.); findings show the notice and a link to each record.

## Only after you confirm (Findings tab, "Fix it here"; Quarantine and fix log tab)

Each fix is a button under the finding it belongs to. It needs the `manage_options` capability, a nonce and a ticked confirmation box (some also need a second box: another copy of a backup exists, a recent site backup exists before updates, a clean theme copy is ready). Lists (C16, H3, B8) have a box per item and act only on the ticked ones. The request names a finding, never a path: the files come from a fresh scan run when you click, which makes no requests to the site, and the fix is refused if the scan no longer reports them; ticked items the fresh scan does not list are ignored. A fix never deletes PHP code other than `wp-content/maintenance.php` for the maintenance fix (PHP is only ever moved, into the reversible quarantine), never follows a link, never moves Earlyward itself, and never leaves the site folder. Each change is recorded in the option `earlyward_fix_log` (last 50: time, user, action, file, sha256 and size, or the old and new permissions). Defining `EARLYWARD_NO_FIXES` as true removes every button and refuses every request. A refresh scan is queued after a successful fix.

| What | Effect | How to undo |
|---|---|---|
| Make wp-config.php owner-only (check B4) | chmod 600 on wp-config.php, only when PHP owns the file. Put back at once if PHP can no longer read it. | The log keeps the old mode; `chmod` it back |
| Delete an exposed backup, dump or config copy (check B3) | Deletes the one file the finding lists. Never `wp-config.php` or any `.php` file. | Not reversible from here. The hash is logged. |
| Delete a leftover maintenance page (check C3, info only) | Deletes `wp-content/maintenance.php`, only when the check classed it as a harmless generated page. | Not reversible from here. The hash is logged. |
| Move a file to quarantine (checks C15 web root, C12 uploads, C13 theme files, D1 readme.html/license.txt) | Moves the one file the finding lists into `wp-content/earlyward-quarantine-<12 random characters>/`, renamed `<time>-<hash>-<name with dots as underscores>.quarantined`, so no `.php` is left anywhere in the name. Only from the place the check looks at (web root, uploads, a theme folder, those two names); never `wp-config.php`, `index.php`, `.htaccess`, `wp-load.php` or `wp-settings.php`. For a theme that is in use, a second box warns that the site may show errors until a clean copy is reinstalled. | "Put back" on the Quarantine and fix log tab, refused if something new is at the original place |
| Move folders to quarantine (C16 renamed plugin copies, H3 inactive plugins; only the ticked ones) | Moves each ticked folder directly inside `wp-content/plugins` into the quarantine folder (a rename, nothing is copied or deleted). The plugin's uninstall code does not run. PHP files inside a moved folder keep their names, so the folder's `.htaccess` is what stops them being requested on Apache/LiteSpeed; on nginx only the random folder name protects them. | "Put back" |
| Update plugins, themes and WordPress (B8; only the ticked ones) | Runs WordPress's own updater (`Plugin_Upgrader`, `Theme_Upgrader`, `Core_Upgrader`: the code behind Dashboard → Updates) for each ticked item, one after the other, plugins and themes first and WordPress last, downloading what WordPress would download from that screen. WordPress is updated to the newest version its own last update check offered; the site is in maintenance mode while it runs. Needs the user's `update_plugins`/`update_themes`/`update_core` capability and a filesystem WordPress can write without FTP details; otherwise refused. | Not reversible from here: restore your backup, or install the older version |
| Delete for good (Quarantine and fix log tab) | Deletes one quarantined file or folder. | Not reversible. The hash is logged. |
| Delete older local backup copies (check H1, housekeeping, info only) | Deletes the UpdraftPlus backup files (`backup_*` in `wp-content/updraft`) of every set older than the newest local set. Offered only when UpdraftPlus's own history lists the newest set as uploaded to remote storage; otherwise the finding is shown without a button. Only files whose names match UpdraftPlus's naming are ever deleted. | Not reversible from here. The hashes are logged; the newest local set and the remote copy stay. |

## Planned (not implemented yet)

| What | Effect | How to undo |
|---|---|---|
| Hardening toggles | Settings applied through WordPress filters (file editor off, XML-RPC off, user enumeration blocked, security headers) | Switch the toggle off |
| Account actions (v1.0) | Demote a suspicious admin to subscriber; revoke their sessions and application passwords. The original role is saved first. | "Restore" button using the saved copy |

**The quarantine folder** is created by the first quarantine fix, inside `wp-content`, with an `.htaccess` that refuses every request (Apache, LiteSpeed), an empty `index.html`, and a `MANIFEST.txt` listing each item's stored name, original place, sha256 and time, so files can be put back by hand without Earlyward. Uninstalling Earlyward deletes the `earlyward_quarantine` option but leaves the folder, because it may hold files you want back; delete it by hand when you are sure.

**D2 (no login protection)** offers a link to Plugins → Add New with a search for two-factor plugins. It installs and writes nothing.

Apart from the confirmed fixes above, nothing in Earlyward deletes, moves or edits theme, plugin, core or upload files.
