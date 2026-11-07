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
- [x] WP-CLI command `wp wallwarden scan`
- [x] Hourly WP-Cron scan with a lock; `wp wallwarden run` and `report`
- [ ] Verify WP-Cron spawning on a real host (not reproducible in the local SQLite test site)
- [ ] Overall time budget, measured
- [x] Email only when findings change
- [ ] Plain admin page

- [ ] i18n pass (finding text is English-only for now)

## v0.2 — dogfood

- [ ] Remaining runtime and hosting checks (A5-A8, B4-B9)
- [ ] C7 opcache serving deleted files, C8 detached PHP processes, C10-C13
- [ ] Site Health integration
- [ ] "Copy this to your host's support" block
- [ ] External probe script
- [ ] Measured cost published in `docs/COST.md`

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
