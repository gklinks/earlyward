# Contributing

Thanks for helping. Wallwarden has a few rules that are stricter than most plugins, because a
security tool that cries wolf, or breaks a site, does more harm than good.

## Ground rules

1. **False positives are bugs.** A check that flags a legitimate site is as wrong as one that misses
   malware. Every new check ships with at least one *malicious* fixture and one *benign* fixture.
2. **Read-only by default.** Checks never write. Any new write must be added to
   [docs/WRITES.md](docs/WRITES.md), sit behind a capability check, a nonce and an explicit
   confirmation, and be reversible.
3. **No network calls** beyond the site's own loopback unless the site owner opts in or clicks a
   button that says what will be contacted.
4. **No shell execution.** Read `/proc` or use PHP APIs instead of `exec`/`shell_exec`.
5. **Fail open.** A check that throws must not stop the scan or the site.
6. **Evidence or nothing.** A finding must point at a file and line, option, user, cron hook or process.
7. **Never paste real incident data.** Sanitise domains, paths, usernames, emails, keys and payloads
   before they enter a fixture, an issue or a commit. Truncate payloads to the few bytes a rule needs.
8. **Measure before claiming.** Docs and copy state only what the test suite or a documented
   measurement shows.

## Development setup

Requirements: PHP 7.4+ (8.3 recommended for tooling) and Composer.

```bash
composer install
composer test        # PHPUnit: unit tests + incident fixtures
composer lint        # PHPCS with WordPress Coding Standards
composer analyse     # PHPStan
```

CI runs the same commands on PHP 7.4 to 8.5, plus the WordPress Plugin Check.

## Adding a check

1. Create the class under `src/Checks/<Group>/`. Implement `Wallwarden\Checks\Check`.
2. Read only through the `Env` you are given; never call `file_get_contents()` or `$wpdb` directly.
3. Add fixtures under `tests/fixtures/<check-id>/<case>/` with an `expected.json`.
4. Add a `docs/checks/<check-id>.md` page: what it detects, why, known false positives, how to fix.

## Commits and pull requests

Small commits, each with passing tests. Describe what you measured, not what you expect.
