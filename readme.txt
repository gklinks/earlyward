=== Earlyward ===
Contributors: gklinks
Tags: security, malware, diagnostics, monitoring, hardening
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Detection, early warning, hardening checks and hosting diagnostics, explained in plain language. Read-only by default.

== Description ==

**Pre-release. Not yet recommended for production sites.**

Earlyward looks for signs of an attack, spots hardening that is missing or broken, and explains hosting and runtime problems in plain language: what is wrong, why it matters, how sure it is, and the exact steps to fix it. Every finding shows its evidence, such as the file and line it matched.

It was built from a real incident on a shared hosting account: a self-healing malware kit that spread across every WordPress site on the account, and a separate outage where a broken site kept answering normally for 12 days.

= What it does NOT do =

* It does not make a site unhackable. Nothing installed inside WordPress can.
* It does not change anything automatically, and it is not a malware cleaner. Scans never write. Most findings have a confirmed fix button: a reversible quarantine for unknown files in the web root, PHP in uploads, injected theme files, version files, renamed plugin copies and inactive plugins (put back or delete for good from the Quarantine and fix log tab); deleting an exposed backup or a leftover maintenance page; owner-only wp-config.php; WordPress's own updater for ticked plugins and themes. Each is logged, and the constant EARLYWARD_NO_FIXES switches them off.
* It is not a firewall and does not block traffic.
* It cannot see a total outage from inside: if PHP is down, so is the plugin.
* It does not contact any outside server unless you switch that on. No telemetry.

= Emergency off switch =

Add `define( 'EARLYWARD_DISABLE', true );` to `wp-config.php`, or delete the plugin folder.

== Frequently Asked Questions ==

= Does it slow down my site? =

Scans run in WP-Cron or WP-CLI, never while a visitor loads a page. Measured costs will be published before the first stable release.

= Where do I report a security problem? =

Privately, through the Security tab of the GitHub repository. Please do not open a public issue.

== Changelog ==

= 0.6.0 =
* Still an early preview: not yet recommended for production sites.
* Fix buttons for most findings, each needing a ticked confirmation and re-checked by a fresh scan at the click.
* New reversible quarantine: unknown files in the web root (C15), PHP in uploads (C12), injected theme files (C13), readme.html and license.txt (D1), renamed plugin copies (C16) and inactive plugins (H3) move into one locked folder in wp-content, renamed so they cannot run. A new tab, Quarantine and fix log, puts items back or deletes them for good.
* Updates waiting (B8): run WordPress's own updater for the plugins and themes you tick, after confirming you have a backup.
* No login protection (D2): a link to search for a two-factor plugin. Earlyward installs nothing.
* C13 now notes whether the theme is in use.

= 0.5.0 =
* Still an early preview: not yet recommended for production sites.
* Built from a real audit of a live site. New checks: unexpected PHP and leftover config files in the web root (C15), renamed plugin copies such as plugin.bak-20260809 (C16), updates waiting (B8), readme.html and license.txt publicly served (D1), no login-protection plugin found (D2), installed but inactive plugins (H3, under Housekeeping).
* Detection only: none of these changes a file.

= 0.4.1 =
* Fix: the update-check health check (B7) raised PHP warnings on real sites, because WordPress's stored update data arrives as an incomplete object. It now reads the data safely. Found by running 0.4.0 on a live site.

= 0.4.0 =
* Still an early preview: not yet recommended for production sites.
* New checks: WordPress has not checked for updates for days (B7), injected code in theme template files (C13, detection only).
* An empty PHP file in uploads is now only a note (found on a real site: leftovers of a 2021 cleanup).

= 0.3.0 =
* Still an early preview: not yet recommended for production sites.
* New checks: PHP past end of life or with a very low memory limit (B5), disk nearly full (B6), PHP files inside the uploads folder (C12), newest backup older than 14 days (H2, shown under Housekeeping, never sends an alert email).
* Each new check stays quiet when it cannot read the value it needs.

= 0.2.0 =
* Still an early preview: not yet recommended for production sites.
* New admin screen (Tools, Earlyward): Overview and Findings tabs, Scan now, Set baseline, a block to copy for your host's support.
* Site Health integration, an admin bar status badge and a dashboard widget. They only show the last stored scan.
* Confirmed one-click fixes: owner-only wp-config.php, delete an exposed backup file, delete a leftover maintenance page, delete older local backup copies (only when the newest backup is proven to be stored remotely). Each fix is logged; EARLYWARD_NO_FIXES switches them off.
* New checks: wp-config.php permissions (B4); known vulnerabilities from the Wordfence Intelligence feed (E1, off unless you define EARLYWARD_WORDFENCE_KEY); older local backup copies (H1, shown under Housekeeping, never sends an alert email).
* B3 now confirms through the site's own loopback that a backup or dump is actually served before raising it to critical.
* Fewer false alarms: a generated maintenance page without malware patterns is info only.
* Translatable text, and `wp earlyward cost` to measure what a scan costs on your site.

= 0.1.0 =
* Early preview. Not yet recommended for production sites.
* Runtime checks: silent outage against a known-good baseline (A1), code switched off after a crash and recovery mode (A2), runaway error logs (A3), WP-Cron not firing (A4).
* Hosting checks: other sites readable by this site's PHP (B1), no open_basedir (B2), backups and dumps the web server can serve (B3), wp-config.php permissions (B4).
* Malware indicators: auto_prepend_file loaders (C1), self-healing zip seeds (C2), unexpected PHP in wp-content (C3), automation-shaped administrators (C4), application passwords on administrators (C5), CSS hiding a user (C6), links hidden off-screen (C9).
* Hourly scan in WP-Cron; email only when warning or critical findings change.
* WP-CLI: `wp earlyward scan`, `run`, `report`, `baseline`. An admin screen (Tools, Earlyward) is in development and not part of 0.1.0.
