# Roadmap

Dates are not promised. A milestone ships when its checks pass their fixtures and the dogfood log
shows they behave on real sites. Check IDs refer to the ranked list in [PHASE-0-PLAN.md](PHASE-0-PLAN.md).

## v0.1 — private dogfood

- [x] Plugin skeleton: bootstrap, kill switch, fail-open wrappers, autoloader
- [x] Finding model with evidence, severity, confidence, fingerprint
- [x] Env layer: filesystem
- [x] Env layer: database, with fixture twin
- [x] Env layer: loopback, baselines, previous observations, clock
- [ ] Env layer: `/proc`
- [x] Fixtures suite (runs locally; CI workflow written, not yet run on GitHub)
- [x] C1 `auto_prepend_file` per-line classification
- [x] C2 hex-named archive seeds
- [x] C3 unrecognised must-use plugins and drop-ins
- [x] C4 look-alike admin accounts
- [x] C5 sessions and application passwords on admin accounts
- [x] C6 `custom_css` hiding a user row
- [x] C9 cloaked off-screen links
- [x] A1 silent outage (loopback vs baseline)
- [x] A2 fatal-error recovery and crash markers
- [x] A3 error log size and repeating lines
- [x] A4 WP-Cron health
- [x] B1 several sites sharing one Linux user
- [x] B2 `open_basedir` unset
- [x] B3 backups and dumps reachable in the docroot
- [x] B4 wp-config.php readable or writable by other accounts
- [x] Confirmed one-click fixes: owner-only wp-config.php (B4), delete exposed backup file (B3), delete leftover maintenance page (C3), with a fix log and a kill switch
- [x] Housekeeping section: H1 older local backup copies, with a confirmed delete only when the newest backup is proven to be stored remotely
- [ ] More fixes, each with its own re-check, tests and undo information: quarantine a suspicious file, revoke an application password, demote an unknown administrator
- [x] Admin bar status badge and dashboard widget (administrators only, read-only)
- [x] WP-CLI command `wp earlyward scan`
- [x] Hourly WP-Cron scan with a lock; `wp earlyward run` and `report`
- [ ] Verify WP-Cron spawning on a real host (not reproducible in the local SQLite test site)
- [x] Overall time budget, measured (docs/COST.md; local site only so far)
- [x] Email only when findings change
- [x] Plain admin page (Tools → Earlyward; in the development branch, not in the 0.1.0 zip)

- [x] i18n pass: admin screen, Site Health, email and all finding text are translatable (`languages/earlyward.pot`); WP-CLI output and the host-support text stay English on purpose

## v0.2 — dogfood

- [x] B5 PHP end of life and memory limit, B6 disk space, C12 PHP in uploads, H2 backup freshness
- [x] B7 update checks stalled, C13 theme template injection
- [ ] Remaining runtime and hosting checks (A5-A8, B8-B9)
- [ ] C7 opcache serving deleted files, C8 detached PHP processes, C10, C11
- [x] Site Health integration (reads the stored scan only; critical -> critical, warning -> recommended)
- [x] "Copy this to your host's support" block (on the admin page)
- [x] Overview tab: waiting updates, pending comments, administrators and newest users, cron jobs by owner, last change in findings (read-only; no login-IP log, no backup tools)
- [x] Backup freshness note (H2: reads the backup plugin's own history; read-only)
- [ ] Optional login log for administrators (opt-in, bounded, with a retention limit)
- [ ] External probe script
- [x] Measured cost published in `docs/COST.md` (local site; real-hosting figures still to come)

## v1.0 — public

- [ ] False-positive gate passed (see the plan, section 7)
- [ ] Hardening toggles (reversible settings only)
- [ ] Reversible account actions: demote, revoke sessions and application passwords, with backup
- [ ] wordpress.org account for the `Contributors:` line in readme.txt
- [ ] wordpress.org submission

## Later candidates

- File quarantine with one-click restore (v1.1 at the earliest)
- C14 core checksum verification (opt-in, contacts api.wordpress.org)
- Multisite network-wide view
