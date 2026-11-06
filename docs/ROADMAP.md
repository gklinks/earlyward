# Roadmap

Dates are not promised. A milestone ships when its checks pass their fixtures and the dogfood log
shows they behave on real sites. Check IDs refer to the ranked list in [PHASE-0-PLAN.md](PHASE-0-PLAN.md).

## v0.1 — private dogfood

- [ ] Plugin skeleton: bootstrap, kill switch, fail-open wrappers, autoloader
- [ ] Finding model with evidence, severity, confidence, fingerprint
- [ ] Env layer (filesystem, database, `/proc`, loopback) with fixture twins
- [ ] Fixtures suite in CI
- [ ] C1 `auto_prepend_file` per-line classification
- [ ] C2 hex-named archive seeds
- [ ] C3 unrecognised must-use plugins and drop-ins
- [ ] C4 look-alike admin accounts
- [ ] C5 sessions and application passwords on admin accounts
- [ ] C6 `custom_css` hiding a user row
- [ ] C9 cloaked off-screen links
- [ ] A1 silent outage (loopback vs baseline)
- [ ] A2 fatal-error recovery and crash markers
- [ ] A3 error log size and repeating lines
- [ ] A4 WP-Cron health
- [ ] B1 several sites sharing one Linux user
- [ ] B2 `open_basedir` unset
- [ ] B3 backups and dumps reachable in the docroot
- [ ] Scanner in WP-Cron with a time budget; WP-CLI command
- [ ] Email only when findings change
- [ ] Plain admin page

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
- [ ] wordpress.org submission

## Later candidates

- File quarantine with one-click restore (v1.1 at the earliest)
- C14 core checksum verification (opt-in, contacts api.wordpress.org)
- Multisite network-wide view
