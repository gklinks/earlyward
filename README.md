# Earlyward

**Status: early preview (0.1). Not yet recommended for production sites.** The code is developed in
the open. It has been tested against the fixtures in this repository and on a local WordPress, not yet
on live hosting. The first release for production sites comes after weeks of testing on real sites
(see [the release gate](docs/PHASE-0-PLAN.md#7-dogfood-and-the-false-positive-gate)). There is no
admin screen yet: results come through WP-CLI and email.

Earlyward is a free, open-source WordPress plugin. It looks for signs of an attack, spots hardening
that is missing or broken, and explains hosting and runtime problems in plain language: what is wrong,
why it matters, how sure it is, and the exact steps to fix it.

It is built from a real incident: a self-healing malware kit that spread across every WordPress site
on one shared hosting account, a second paid-backlink campaign hiding inside the same sites, and a
separate outage where the site kept answering HTTP 200 while it was broken, for 12 days, without
anyone noticing.

## What works in 0.1

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
- **B3** Backups, database dumps and config copies where the web server can serve them.

**Malware indicators**

- **C1** Hidden `auto_prepend_file` / `auto_append_file` loaders in `.user.ini`, `php.ini` or `.htaccess`.
- **C2** Hex-named zip archives that malware uses to rebuild itself.
- **C3** Unexpected PHP: must-use plugins, drop-ins, hidden dot-files and loose PHP in `wp-content`.
- **C4** Administrator accounts shaped like automation rather than people.
- **C5** Application passwords on administrator accounts (they survive password changes).
- **C6** Custom CSS that hides a user from the Users screen.
- **C9** Links hidden off-screen inside published content.

**Alerts that are not noise.** Scans run hourly in WP-Cron, never on visitor requests. Email goes out
only when the set of warning or critical findings changes.

## Planned

An admin screen and Site Health integration, more checks (PHP limits, disk space, certificates,
opcache), a "copy this to your host's support" block, reversible one-click hardening, and an external
probe script. See the [roadmap](docs/ROADMAP.md).

## What it does NOT do

- It does **not** make a site unhackable. Nothing installed inside WordPress can.
- It does **not** remove malware or edit your files automatically. It is read-only by default; every
  write it can make is listed in [docs/WRITES.md](docs/WRITES.md) and needs your confirmation.
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
```

## Turning it off in an emergency

Add `define( 'EARLYWARD_DISABLE', true );` to `wp-config.php`, or delete the
`wp-content/plugins/earlyward` folder. Either one stops it completely.

## Documentation

- [Roadmap](docs/ROADMAP.md)
- [Threat model](docs/THREAT-MODEL.md)
- [Everything the plugin can write](docs/WRITES.md)
- [Plan and design](docs/PHASE-0-PLAN.md)
- [Reporting a security issue](SECURITY.md)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
