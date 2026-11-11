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

## What works in 0.8

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
- **B8** Plugin, theme or core updates are waiting (a note that names them).
- **D1** `readme.html` and `license.txt` that state the exact WordPress version are publicly served (a low-confidence note).
- **D3** The home page is sent without standard security headers (HSTS of at least six months, `nosniff`, clickjacking protection, referrer policy, a content security policy). One header-only request to the site's own home page, as a visitor gets it, so headers added by Cloudflare count. A note; it never sends an alert.
- **D2** None of the well-known login-protection or two-factor plugins is active (a low-confidence note; it cannot see host or Cloudflare protection).
- **B7** WordPress has not managed to check for updates for days (reads the timestamps WordPress already stored; makes no request).
- **B5** PHP past end of life (or within 90 days of it), and a PHP memory limit under 64 MB.
- **B6** A disk that is nearly full (free space as a percentage and in absolute terms).
- **H4** (housekeeping, info only) five or more subscriber or customer accounts registered over two years ago with no sign-in session and no recent shop activity. It reports counts only, never names or addresses, and Earlyward never deletes accounts.
- **H3** (housekeeping, info only) installed plugins that are not active.
- **H2** (housekeeping, info only) the newest backup in UpdraftPlus's own history is more than 14 days old.
- **H1** (housekeeping, info only) older local UpdraftPlus backup copies that use space on the server. It offers to delete them only when UpdraftPlus's own history shows the newest backup was uploaded to remote storage. It is shown in its own "Housekeeping" section and never sends an alert.
- **B3** Backups, database dumps and config copies where the web server can serve them. For archives it asks the site itself for the file's headers, and an archive that is really being served is reported as critical.

**Malware indicators**

- **C17** The uploads folder is not set to refuse PHP (reads `wp-content/uploads/.htaccess`, sends no test file). One confirmed fix creates the protective file when none exists on Apache or LiteSpeed; nginx gets the rule to add by hand.
- **C1** Hidden `auto_prepend_file` / `auto_append_file` loaders in `.user.ini`, `php.ini` or `.htaccess`.
- **C2** Hex-named zip archives that malware uses to rebuild itself.
- **C3** Unexpected PHP: must-use plugins, drop-ins, hidden dot-files and loose PHP in `wp-content`.
- **C4** Administrator accounts shaped like automation rather than people.
- **C5** Application passwords on administrator accounts (they survive password changes).
- **C6** Custom CSS that hides a user from the Users screen.
- **C9** Links hidden from visitors inside published content: moved off-screen, in a tiny box (1-2px with overflow hidden), font-size 0, large negative text-indent, clipped, or hidden with display:none / visibility:hidden / opacity:0. It reads page text and the data of Elementor, Beaver Builder, Bricks and Oxygen (published posts only). Detects common hiding patterns, not all of them. Hidden blocks of the display:none kind are reported only with 3 or more external links, or an external link whose text is not shown elsewhere on the page, so tabs, accordions and menus stay quiet. Own-site links are recognised from the home and siteurl settings (with www. and without are the same); if the site address cannot be read, that display:none rule stays quiet. It checks up to 1000 matching items per source and says so when that limit is reached. Under a finding, "Fix it here" removes the hidden blocks you tick from the post (page text and Elementor-style JSON builder data), after saving the old values; "Put back" on the Quarantine and fix log tab restores them unless the post has changed since. Passwords must still be changed. Each finding also says when the hidden block first shows up in the post's saved revisions (up to 200 per post, autosaves included, page text and builder data) and which user saved that revision, as "at least since" when the limit is reached or "no revision history" when none has it; that user may have had their login stolen, so change their password.
- **C18** SEO spam leftovers (detection only, warning; critical when a matching post is published): (1) categories, tags, product categories or other terms, empty ones too, whose name or slug contains a word from a fixed list; (2) posts and pages in any status except trash (published, draft, private, pending, scheduled) whose title or slug contains one, or whose title is mostly Chinese, Japanese or Korean characters on a site whose other titles are not; (3) subscriber and customer accounts with one in the login or display name (count and up to 5 logins). The list is: casino, poker, slot(s), betting, gambling, viagra, cialis, payday loan, essay writing, replica, escort, crypto bonus; whole words only, any case, also inside hyphenated slugs, so "slotted spoon" stays quiet but a category called "Slots & Tabs" is flagged for you to look at. The character rule is off when the site language is Japanese, Chinese or Korean, when most titles are in those scripts, and when fewer than 3 titles are in other scripts. A word list finds the common cases, not all spam, and a match is a reason to look, not proof.
- **C15** PHP files in the web root that WordPress core does not ship, and renamed server-config or maintenance leftovers (detection only).
- **C16** Renamed plugin copies such as `plugin.bak-20260809`, which WordPress no longer lists or updates but the web can still reach.
- **C13** Code that hides or runs other code in a theme's functions.php, header.php or footer.php (detection only; never edits a theme).
- **C12** PHP files inside the uploads folder (tiny "Silence is golden" placeholders are ignored; an empty file is only a note; files with malware traits are critical).

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
- It does **not** change anything automatically. Scans never write. Most findings have a fix button on the
  Findings tab, and nothing happens until an administrator ticks the confirmation and clicks:
  - **Quarantine (reversible):** unknown files in the web root (C15), PHP in uploads (C12), injected theme
    files (C13, with an extra warning when the theme is in use), `readme.html`/`license.txt` (D1), renamed
    plugin copies (C16) and inactive plugins you tick (H3) are moved into one locked folder in `wp-content`
    and renamed so they cannot run. The **Quarantine and fix log** tab puts each one back, or deletes it for good.
  - **Delete:** an exposed backup file (B3), a harmless leftover maintenance page (C3), older local backup
    copies once the newest is proven to be stored elsewhere (H1).
  - **Other:** make `wp-config.php` owner-only (B4); run WordPress's own updater for the plugins, themes and
    WordPress itself that you tick (B8, after an "I have a backup" box; WordPress itself runs last), with the
    newest backup's time shown above and a "Back up now" button that starts UpdraftPlus's own full backup;
    a link to find a two-factor plugin (D2, Earlyward installs nothing).

  Each fix re-checks the finding at the moment you click (the request names a finding, never a path), only
  acts on what that fresh scan reports, and is recorded in a fix log. Set `EARLYWARD_NO_FIXES` to switch them
  all off. Every write is listed in [docs/WRITES.md](docs/WRITES.md). Earlyward is not a malware cleaner:
  quarantining what it found does not prove the site is clean.
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
