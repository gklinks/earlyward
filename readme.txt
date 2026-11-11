=== Earlyward ===
Contributors: gklinks
Tags: security, malware, diagnostics, monitoring, hardening
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Detection, early warning, hardening checks and hosting diagnostics, explained in plain language. Read-only by default.

== Description ==

**Pre-release. Not yet recommended for production sites.**

Earlyward looks for signs of an attack, spots hardening that is missing or broken, and explains hosting and runtime problems in plain language: what is wrong, why it matters, how sure it is, and the exact steps to fix it. Every finding shows its evidence, such as the file and line it matched.

It was built from a real incident on a shared hosting account: a self-healing malware kit that spread across every WordPress site on the account, and a separate outage where a broken site kept answering normally for 12 days.

= What it does NOT do =

* It does not make a site unhackable. Nothing installed inside WordPress can.
* It does not change anything automatically, and it is not a malware cleaner. Scans never write. Most findings have a confirmed fix button: a reversible quarantine for unknown files in the web root, PHP in uploads, injected theme files, version files, renamed plugin copies and inactive plugins (put back or delete for good from the Quarantine and fix log tab); deleting an exposed backup or a leftover maintenance page; owner-only wp-config.php; WordPress's own updater for ticked plugins, themes and WordPress itself. Each is logged, and the constant EARLYWARD_NO_FIXES switches them off.
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

= 0.10.0 =
* Still an early preview: not yet recommended for production sites.
* C9 finds links that visitors cannot see in more ways: a tiny box (1-2px with overflow hidden), text moved off-screen, font-size 0, negative text-indent, clipping, and display:none, visibility:hidden or opacity:0 blocks that hold several external links or text shown nowhere else. It also searches the data of Elementor, Beaver Builder, Bricks and Oxygen, not only the page text. It detects common hiding patterns, not all of them.
* C9 has a "Remove hidden links" fix under its finding ("Fix it here"): tick the hidden blocks to remove, and Earlyward saves the old values first and checks the result. "Put back" on the Quarantine and fix log tab restores them, but only while the post is still as the fix left it. Change the administrator passwords too.
* C9 timeline: each finding now says when the hidden block first appears in the post's saved revisions (up to 200 revisions per post) and which user saved that revision, because that user may have had their login stolen. No revision history is not proof that nothing happened.
* New check C18, SEO spam leftovers: categories, tags and other terms (empty ones too), posts and pages in any status except trash, and subscriber or customer accounts whose name or slug contains a word from a fixed list (casino, poker, slots, betting, gambling, viagra, cialis, payday loan, essay writing, replica, escort, crypto bonus). It also flags posts whose title is mostly Chinese, Japanese or Korean characters on a site whose other titles are not. A word list finds common cases, not all spam; a match is a reason to look, not proof. Detection only.
* C18 is listed in the Site Health "found nothing" list when it runs and reports nothing.

= 0.9.0 =
* Still an early preview: not yet recommended for production sites.
* New check D3: the home page is sent without standard security headers (HSTS of at least six months, nosniff, clickjacking protection, referrer policy, a content security policy). One header-only request to the site's own home page; a note, never an alert.
* New check C17: the uploads folder is not set to refuse PHP. A new confirmed fix creates one protective .htaccess in uploads, only when none exists and the server reads .htaccess (Apache, LiteSpeed). For nginx the finding shows the rule to add.
* New housekeeping note H4: many subscriber or customer accounts look unused. It only counts them (no names or addresses) and never deletes anything.

= 0.8.0 =
* Backup before updating: every update form now shows when the newest backup was made and whether it was also sent to remote storage (from UpdraftPlus's own history), with a "Back up now" button that starts UpdraftPlus's full backup in the background. Earlyward does not make backups itself; without UpdraftPlus it links to it.

= 0.7.2 =
* Fix buttons no longer fail silently when a box is missed: the browser refuses to send the form until the confirmation boxes are ticked, a list form says "Tick at least one item" next to its button, and the message after a refused click names the missing box.

= 0.7.1 =
* The Overview tab's "Updates waiting" table now has a tick box per plugin, theme and WordPress itself, and an "Update the ticked items now" button (the same confirmed fix as on the Findings tab). Before, the update button was only on the Findings tab, at the bottom.

= 0.7.0 =
* Still an early preview: not yet recommended for production sites.
* Updates waiting (B8): the update button now also updates WordPress itself, with the same updater as Dashboard, Updates. Plugins and themes run first, WordPress last, after the "I have a recent backup" box.

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
* Malware indicators: auto_prepend_file loaders (C1), self-healing zip seeds (C2), unexpected PHP in wp-content (C3), automation-shaped administrators (C4), application passwords on administrators (C5), CSS hiding a user (C6), hidden links in content and page-builder data (C9).
* Hourly scan in WP-Cron; email only when warning or critical findings change.
* WP-CLI: `wp earlyward scan`, `run`, `report`, `baseline`. An admin screen (Tools, Earlyward) is in development and not part of 0.1.0.
