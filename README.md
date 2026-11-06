# Wallwarden

**Status: pre-release. Not ready for production sites.** The first release will go public only after
weeks of testing on real sites (see [the release gate](docs/PHASE-0-PLAN.md#7-dogfood-and-the-false-positive-gate)).

Wallwarden is a free, open-source WordPress plugin. It looks for signs of an attack, spots hardening
that is missing or broken, and explains hosting and runtime problems in plain language: what is wrong,
why it matters, how sure it is, and the exact steps to fix it.

It is built from a real incident: a self-healing malware kit that spread across every WordPress site
on one shared hosting account, a second paid-backlink campaign hiding inside the same sites, and a
separate outage where the site kept answering HTTP 200 while it was broken, for 12 days, without
anyone noticing.

## What it does

- **Runtime and outage checks.** Notices when your site loads but is broken (styles gone, custom pages
  returning 404), fatal-error recovery mode, runaway error logs, and WP-Cron that has stopped firing.
- **Hosting health.** Warns when several WordPress sites share one Linux user (one break-in reaches
  all of them), when backups sit where anyone can download them, and when PHP limits, disk space or
  certificates are about to cause trouble. Includes a "copy this to your host's support" block.
- **Malware indicators.** Checks for hidden `auto_prepend_file` loaders, self-healing archive seeds,
  unknown must-use plugins, look-alike admin accounts, application passwords that survive password
  changes, links hidden off-screen, and more. Every finding shows the evidence (file and line, user,
  option or process) so you can judge it yourself.
- **Hardening.** One-click settings only where the fix is a reversible setting.
- **Alerts that are not noise.** Email only when the set of findings changes.

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

Not yet released. Build from source: see [CONTRIBUTING.md](CONTRIBUTING.md).

## Turning it off in an emergency

Add `define( 'WALLWARDEN_DISABLE', true );` to `wp-config.php`, or delete the
`wp-content/plugins/wallwarden` folder. Either one stops it completely.

## Documentation

- [Roadmap](docs/ROADMAP.md)
- [Threat model](docs/THREAT-MODEL.md)
- [Everything the plugin can write](docs/WRITES.md)
- [Plan and design](docs/PHASE-0-PLAN.md)
- [Reporting a security issue](SECURITY.md)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
