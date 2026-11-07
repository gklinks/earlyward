# Phase 0 plan

Status: **accepted 2026-11-06.**

## 0. Decisions (owner, 2026-11-06)

| Decision | Outcome |
|---|---|
| Name | **Earlyward**, slug `earlyward` (owner, 2026-11-07; replaced the working name Wallwarden). Free at the time on wordpress.org, GitHub and as .com/.org/.dev; "WP Warden" is an existing commercial WordPress care service. Trademark registries not yet searched. |
| Repo owner | GitHub organisation `gklinks` (the owner's company), repo `gklinks/earlyward`, private until the owner approves going public. Website, when needed: a `gklinks.com` subdomain on static hosting, not on a WordPress install. |
| Minimum versions | **PHP 7.4+, WordPress 6.4+.** |
| Quarantine | **No file quarantine in v1.0.** v1.0 ships reversible account actions (demote, revoke sessions and application passwords, with a backup). File quarantine is a v1.1 candidate after dogfood. |
| wordpress.org | Built to the directory guidelines from the first commit; submitted at v1.0, after the false-positive gate. |

This file is public. It contains no real domains, server paths, account names or credentials. The
incident evidence it draws on is summarised by *shape*, not by name.

---

## 1. What the plugin is (and is not)

Detection, early warning, hardening and diagnostics for WordPress, in plain language.

It is **not** a firewall, not a malware remover, and cannot protect a site whose server is already
compromised: a plugin runs inside the thing being attacked. If PHP is dead, the plugin is dead too,
so a total outage can only be caught from outside (section 6, "external probe").

## 2. Architecture

```
            WP-Cron (chunked, time-budgeted)       WP-CLI: wp earlyward scan|report|ack
                         \                          /
                          v                        v
                   +------------------- Scanner -------------------+
                   |  registry of Checks, cursor for resumable     |
                   |  slices, per-check time + memory accounting   |
                   +-----------------------------------------------+
                          |  reads only, through an Env layer
          +---------------+---------------+---------------+---------------+
          | Filesystem    | Database      | Proc (/proc)  | Loopback HTTP |
          | (bounded walk,| ($wpdb,       | (no shell,    | (own site     |
          |  no symlinks) |  prepared)    |  Linux only)  |  only)        |
          +---------------+---------------+---------------+---------------+
                          |
                          v
     Finding { id, check, severity, confidence, title, why, evidence[file:line + excerpt],
               fix_steps, fingerprint }
                          |
          +---------------+-------------------+----------------------+
          | Store          | Notifier         | Admin UI / Site Health|
          | (non-autoload  | (email only when | (Tools -> Earlyward, |
          |  option)       |  fingerprint set |  "copy for your host" |
          |                |  changes)        |  block)               |
          +----------------+------------------+-----------------------+
```

Key design choices:

- **Every check is a pure function of an `Env`.** The real `Env` reads the live site; the fixture `Env`
  reads a directory tree plus a JSON snapshot of DB rows and `/proc`. That is what makes a
  fixtures suite built from real incidents runnable in CI without a WordPress install.
- **Evidence is mandatory.** A check that cannot point at a file + line (or option name, user ID,
  cron hook, PID) does not emit a finding. Excerpts are truncated and escaped on output.
- **Confidence is separate from severity.** "High severity, low confidence" is a real, honest state
  (for example a hex-named option that might be a cache key).
- **Fail open.** Every entry point (cron callback, admin page, CLI, REST if any) is wrapped in
  `try/catch (\Throwable)`; a failure records an internal error finding and returns. Kill switch:
  `define( 'EARLYWARD_DISABLE', true );` in `wp-config.php`, or delete the plugin folder.
- **No front-end cost.** Nothing hooks a public request except, optionally, hardening toggles
  that core already exposes as filters (XML-RPC off, security headers).
- **No runtime Composer dependencies.** Own PSR-4 autoloader; dev tools only via Composer.

## 3. Directory layout

```
earlyward/
  earlyward.php            bootstrap: version guard, kill switch, try/catch, autoloader
  uninstall.php             removes only what docs/WRITES.md lists
  readme.txt                wordpress.org
  src/
    Plugin.php
    Scanner/                Scanner, Cursor, Budget
    Finding/                Finding, Severity, Confidence, Fingerprint
    Env/                    Filesystem, Database, Proc, Loopback (+ Fixture* twins)
    Checks/
      Runtime/              outage, crash/recovery, error_log, cron, ttfb, cache, waf
      Hosting/              php limits/EOL, disk, shared-user, tls, outbound, mail, backups
      Integrity/            user.ini, seeds, mu-plugins, dropins, users, options, cron, content, proc, opcache
      Hardening/            file-edit, xmlrpc, enumeration, headers, uploads-php, perms, exposure
    Notify/                 diff + wp_mail
    Admin/                  page, Site Health tests, host-support block
    Cli/                    wp earlyward ...
  templates/  assets/  languages/
tests/
  unit/                     check logic against fixtures
  fixtures/<case>/          tree/, db.json, proc.json, expected.json  (malicious AND benign)
  integration/              wp-env / WP test suite
docs/
  PHASE-0-PLAN.md  ROADMAP.md  THREAT-MODEL.md  WRITES.md  COST.md  checks/<id>.md
bin/                        build-zip, external probe script
.github/                    CI, issue + PR templates
private/                    GITIGNORED: dogfood log, raw evidence, never committed
```

## 4. Detection list, ranked by value to effort

Value = how often it mattered in real incidents and how bad the miss was. Effort = S/M/L.
"Net" means it needs a network call beyond the site's own loopback, so it runs only on an explicit
admin click or opt-in.

### A. Runtime and outage (milestone 1)

| # | Check | Value | Effort | Notes |
|---|---|---|---|---|
| A1 | Silent outage: loopback homepage vs a known-good baseline (own stylesheet link, menu markup, configured custom routes return 200) | Very high | M | A real outage served HTTP 200, unstyled, for 12 days. Status code alone is useless. |
| A2 | Fatal-error recovery state, paused plugins/themes, known crash-marker files | Very high | S | Exact core option names to be confirmed from core source, not memory. |
| A3 | `error_log` / `debug.log` size, growth rate, top repeating line | High | S | Real cases: multi-GB logs from one repeating warning. Also flag a log level set where core overrides it. |
| A4 | WP-Cron health: overdue events, last run age, `DISABLE_WP_CRON` with no system cron evidence | High | S | Also protects our own scans: if cron is dead, say so. |
| A5 | Repeated "terminated while loading" / kill patterns in the log tail | Med | M | Possible host process kill; give the "ask your host" text, never assert it. |
| A6 | Slow TTFB on loopback (median of N) | Med | S | |
| A7 | Stale full-page cache: compare cache-busted vs normal loopback | Med | M | |
| A8 | WAF symptoms on loopback: empty body, challenge page with HTTP 200 | Med | M | |

### B. Hosting health (milestone 1)

| # | Check | Value | Effort | Notes |
|---|---|---|---|---|
| B1 | **Several WordPress installs share one Linux user** (sibling `wp-config.php` readable from this PHP) | Very high | S | Root cause of a 28-site spread. Checks existence/readability only, never reads sibling contents. |
| B2 | `open_basedir` unset | High | S | Pairs with B1. |
| B3 | Backup archives or SQL dumps reachable inside the docroot | Very high | S | A real retired-site zip with live DB credentials was publicly downloadable. |
| B4 | Backups that "succeeded" but are truncated (archive size trend, zip central directory valid) | High | M | Check bytes, not log text. |
| B5 | PHP EOL, `memory_limit`, `max_execution_time` | Med | S | Plain-language layer over what Site Health partly shows. |
| B6 | Disk free; inode usage best effort | Med | S | |
| B7 | Outbound HTTP blocked: infer from core's own update-check timestamps (zero extra calls) | Med | S | |
| B8 | TLS certificate expiry via loopback peer certificate | Med | S | |
| B9 | Mail: record `wp_mail_failed`; SPF/DMARC lookup on click | Med | M | Net (DNS) on click only. |

### C. Integrity and malware indicators (milestone 2)

| # | Check | Value | Effort | Notes |
|---|---|---|---|---|
| C1 | `auto_prepend_file`: effective `ini_get` value + `.user.ini` / `.htaccess` at docroot AND `wp-content/`, **classified per line** | Very high | S | Hybrid files (genuine WAF line + malicious line) are a fixture. |
| C2 | Hex-named `.zip` seeds in `wp-content/` and `uploads/` (bounded walk) | Very high | S | The self-healing seed. |
| C3 | Unrecognised mu-plugins, `wp-<hex>.php` channels, hidden `.<name>.php` files, unexpected drop-ins (`db.php`, `advanced-cache.php`) | Very high | M | Owner can acknowledge a file by sha256; a change re-alerts. |
| C4 | Rogue admins: `{word}_{6-12 hex}` login whose email local-part equals the login; creation bursts across a short window | Very high | S | Shape, not a name list. |
| C5 | Sessions and **application passwords** on admin accounts (count, names, last used) | Very high | S | They survive password changes. |
| C6 | `custom_css` hiding a user row | High | S | |
| C7 | Opcache serving scripts whose file no longer exists | High | S | Needs `opcache_get_status`; may be restricted. |
| C8 | Detached PHP processes running a dot-prefixed or deleted file, via `/proc` (no shell) | High | M | Linux only, best effort, honest "could not check" otherwise. |
| C9 | Cloaked links: `style="..."` **attribute** with fixed/absolute position and an offset of 1000px or more, in published posts, pages, blocks, widgets | High | S | Decorative CSS inside `<style>` is a benign fixture and must not match. |
| C10 | Option control planes: known-campaign prefixes + structural shape (hex name, large encoded value) | High | M | Prefix rules have a shelf life; structural rules carry the FP risk. |
| C11 | Cron hooks with random-shaped names and no registered callback | High | M | Orphans from deactivated plugins are a benign fixture (Info, not Critical). |
| C12 | PHP files in `uploads/`; whether uploads executes PHP | High | M | Execution probe is a documented write, opt-in. |
| C13 | Theme `functions.php` injection markers (prepend or append) | Med | S | **Detection only, never edited.** |
| C14 | Core file checksums | High | M | Net (api.wordpress.org), opt-in. |

### D. Hardening (milestone 3), one-click only where the fix is a reversible setting

File edit off, XML-RPC off, user enumeration blocked, four safe security headers (no blanket CSP),
uploads PHP execution blocked (documented `.htaccess` write, Apache/LiteSpeed only),
`wp-config.php` permissions (report; fix behind confirmation), debug log publicly reachable,
directory listing, auto-updates. HTTPS enforcement is report-only (redirect loops are a real risk).

## 5. MVP cut

**v0.1 (private dogfood):** A1-A4, B1-B3, C1-C6, C9, email-on-change, WP-CLI, a plain admin page.
Everything here is either cheap or already proven by the hand-written monitor this plugin replaces.

**v0.2 (dogfood):** rest of A and B, C7-C13, Site Health integration, "copy for your host" block.

**v1.0 (public):** D hardening toggles, reversible account actions, docs, wordpress.org assets.
Public release only after the false-positive gate in section 7.

## 6. Writes, network, cost (to be measured, not assumed)

- Writes (full list kept in `docs/WRITES.md`): own options and transients, one cron event,
  and only with confirmation: hardening toggles and reversible account actions.
- Network: loopback to the site's own URL only. Anything else is click-to-run or opt-in.
- External probe: a tiny script in `bin/` the owner can run from any other machine or cron to catch
  the outage the plugin cannot see from inside (site fully down, or plugin itself not loading).
- Cost targets per cron slice (to be **measured** on the dogfood sites and published in `docs/COST.md`):
  wall time under a fixed budget with a resumable cursor; bounded file walk; no unbounded queries.

## 7. Dogfood and the false-positive gate

- Install on the owner's live sites before any public release; log every finding as true positive,
  false positive or unclear in `private/dogfood-log.md` (gitignored).
- Every false positive becomes a sanitised benign fixture before the rule is changed.
- Proposed gate for v1.0: **14 consecutive days, zero false-positive Critical findings across all
  dogfood sites, and every Warning-level false positive covered by a fixture.**

## 8. Local tooling gap (found during Phase 0)

The development machine currently has no PHP, Composer, Docker or WSL. Options: install PHP 8.3 +
Composer locally, or rely on GitHub Actions for every run. Real entry points must be executed
somewhere before anything is called "tested".
