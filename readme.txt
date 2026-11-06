=== Wallwarden ===
Contributors: wallwarden
Tags: security, malware, diagnostics, monitoring, hardening
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Detection, early warning, hardening checks and hosting diagnostics, explained in plain language. Read-only by default.

== Description ==

**Pre-release. Not yet recommended for production sites.**

Wallwarden looks for signs of an attack, spots hardening that is missing or broken, and explains hosting and runtime problems in plain language: what is wrong, why it matters, how sure it is, and the exact steps to fix it. Every finding shows its evidence, such as the file and line it matched.

It was built from a real incident on a shared hosting account: a self-healing malware kit that spread across every WordPress site on the account, and a separate outage where a broken site kept answering normally for 12 days.

= What it does NOT do =

* It does not make a site unhackable. Nothing installed inside WordPress can.
* It does not remove malware or edit your files. It is read-only by default.
* It is not a firewall and does not block traffic.
* It cannot see a total outage from inside: if PHP is down, so is the plugin.
* It does not contact any outside server unless you switch that on. No telemetry.

= Emergency off switch =

Add `define( 'WALLWARDEN_DISABLE', true );` to `wp-config.php`, or delete the plugin folder.

== Frequently Asked Questions ==

= Does it slow down my site? =

Scans run in WP-Cron or WP-CLI, never while a visitor loads a page. Measured costs will be published before the first stable release.

= Where do I report a security problem? =

Privately, through the Security tab of the GitHub repository. Please do not open a public issue.

== Changelog ==

= 0.1.0 =
* Pre-release: auto_prepend_file loader check (C1) and zip seed check (C2), WP-CLI command.
