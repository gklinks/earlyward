# Earlyward

**Status: early preview (0.2). Not yet recommended for production sites.** The code is developed in
the open. It has been tested against the fixtures in this repository, on a local WordPress and on one live site, not yet
on a range of real hosting. The first release for production sites comes after weeks of testing on real sites
(see [the release gate](docs/PHASE-0-PLAN.md#7-dogfood-and-the-false-positive-gate)).

0.2.0 adds an admin screen under Tools → Earlyward: an Overview tab (waiting updates, pending comments, administrators and newest users, scheduled jobs and who registered them, when the findings last changed) and a Findings tab (evidence, fix steps, Scan now, Set baseline, a block to copy for your host's support, and a Housekeeping section for tidy-up suggestions that are not security problems). Site Health (Tools → Site Health) lists each warning or critical finding, one entry for the checks that found nothing, one for any check that could not run, and an Earlyward block on its Info tab. Administrators also get a coloured status badge in the admin bar (on the site and in wp-admin) and a widget on the dashboard home; both only show the last stored scan.

Earlyward is a free, open-source WordPress plugin. It looks for signs of an attack, spots hardening
that is missing or broken, and explains hosting and runtime problems in plain language: what is wrong,
why it matters, how sure it is, and the exact steps to fix it.

It is built from a real incident: a self-healing malware kit that spread across every WordPress site
on one shared hosting account, a second paid-backlink campaign hiding inside the same sites, and a
separate outage where the site kept answering HTTP 200 while it was broken, for 12 days, without
anyone noticing.

## What works in 0.3

Every finding says what is wrong, how sure the check is, and the exact steps to fix it, and shows its
evidence (file and line, user, option or URL) so you can judge it yourself.

**Runtime and outage**

- **A1** The site answers but is broken: compared with a known-good baseline of the homepage, its own
  stylesheets, menu or REST routes have gone missing, or the homepage returns an error or an empty page.
- **A2** Code switched off after a crash, and fatal errors WordPress caught (recovery mode).
- **A3** Error logs that are huge, growing fast, or dominated by one repeating line.
- **A4** WP-Cron that has stopped firing.

**Hosting**

- **B1** Other WordPress sites readable by this site's PHP (one break-in reaches all of them).
- **B2** No `open_basedir` limit, so PHP can open files anywhere the account can.
- **B4** `wp-config.php` that other accounts on the server can read (a note) or change (a warning). It does not help against sites that share one system user; B1 covers that.
- **B5** PHP past end of life (or within 90 days of it), and a PHP memory limit under 64 MB.
- **B6** A disk that is nearly full (free space as a percentage and in absolute terms).
- **H2** (housekeeping, info only) the newest backup in UpdraftPlus's own history is more than 14 days old.
- **H1** (housekeeping, info only) older local UpdraftPlus backup copies that use space on the server. It offers to delete them only when UpdraftPlus's own history shows the newest backup was uploaded to remote storage. It is shown in its own "Housekeeping" section and never sends an alert.
- **B3** Backups, database dumps and config copies where the web server can serve them. For archives it asks the site itself for the file's headers, and an archive that is really being served is reported as critical.

**Malware indicators**

- **C1** Hidden `auto_prepend_file` / `auto_append_file` loaders in `.user.ini`, `php.ini` or `.htaccess`.
- **C2** Hex-named zip archives that malware uses to rebuild itself.
- **C3** Unexpected PHP: must-use plugins, drop-ins, hidden dot-files and loose PHP in `wp-content`.
- **C4** Administrator accounts shaped like automation rather than people.
- **C5** Application passwords on administrator accounts (they survive password changes).
- **C6** Custom CSS that hides a user from the Users screen.
- **C9** Links hidden off-screen inside published content.
- **C12** PHP files inside the uploads folder (tiny "Silence is golden" placeholders are ignored; files with malware traits are critical).

**Known vulnerabilities (opt-in)**

- **E1** Plugins, themes or WordPress versions with a published vulnerability, matched on your server
  against the Wordfence Intelligence feed. Off unless you define `EARLYWARD_WORDFENCE_KEY`; see
  [docs/WRITES.md](docs/WRITES.md) for exactly what is contacted.

**Alerts that are not noise.** Scans run hourly in WP-Cron, never on visitor requests. Email goes out
only when the set of warning or critical findings changes.

## Planned

More checks (PHP limits, disk space, certificates,
opcache), reversible one-click hardening, and an external
probe script. See the [roadmap](docs/ROADMAP.md).

## What it does NOT do

- It does **not** make a site unhackable. Nothing installed inside WordPress can.
- It does **not** remove malware or change anything automatically. Scans never write. A few fixes exist as
  buttons on the Findings tab (make `wp-config.php` owner-only, delete an exposed backup file, delete a
  harmless leftover maintenance page, delete older local backup copies once the newest is proven to be stored
  elsewhere). Each one needs a confirmation, re-checks the finding at the moment
  you click, never touches PHP code other than those two named files (wp-config.php and maintenance.php), and is recorded in a fix log. Set
  `EARLYWARD_NO_FIXES` to switch them all off. Every write is listed in [docs/WRITES.md](docs/WRITES.md).
- It is **not** a firewall and does not block traffic.
- It **cannot** see a total outage from inside: if PHP is down, so is the plugin. Use the external
  probe script (planned) or any uptime monitor for that.
- It does **not** save a site whose server is already compromised. If a scan finds signs of an active
  infection on a shared hosting account, you will need to clean every site on that account at once.
- It does **not** contact any outside server unless you switch that on. No telemetry.

## Requirements

PHP 7.4 or newer, WordPress 6.4 or newer.

## Install

Try the preview on a test site, not a production one. Download `earlyward-<version>.zip` from
[Releases](https://github.com/gklinks/earlyward/releases) and upload it under Plugins → Add New →
Upload Plugin (or build it from source, see [CONTRIBUTING.md](CONTRIBUTING.md)). Then:

```sh
wp plugin activate earlyward
wp earlyward scan              # read-only scan, prints findings
wp earlyward run --no-mail     # scan and store the results, without email
wp earlyward report            # last stored results
wp earlyward cost --runs=10    # time and memory per check
```

## Turning it off in an emergency

Add `define( 'EARLYWARD_DISABLE', true );` to `wp-config.php`, or delete the
`wp-content/plugins/earlyward` folder. Either one stops it completely.

## Documentation

- [Roadmap](docs/ROADMAP.md)
- [Threat model](docs/THREAT-MODEL.md)
- [What a scan costs, measured](docs/COST.md)
- [Everything the plugin can write](docs/WRITES.md)
- [Plan and design](docs/PHASE-0-PLAN.md)
- [Reporting a security issue](SECURITY.md)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
