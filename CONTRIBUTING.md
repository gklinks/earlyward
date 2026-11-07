# Contributing

Thanks for helping. Earlyward has a few rules that are stricter than most plugins, because a
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
9. **Everything a site owner reads is translatable.** Titles, "why" text and fix steps go through
   `__()` with the text domain `earlyward` (whole sentences, numbered placeholders and a
   `/* translators: */` comment when there is more than one; `_n()` for counts). Evidence (file names,
   excerpts, option names) is data and stays untranslated. WP-CLI output and the "copy for host
   support" block stay English on purpose: they are for developers and hosting support.
   `TranslationTest` fails if a check shows hard-coded English. Refresh the template with
   `wp i18n make-pot . languages/earlyward.pot --slug=earlyward --exclude=vendor,tests,build,bin,docs,private`.

## Development setup

Requirements: PHP 7.4+ (8.3 recommended for tooling) and Composer.

```bash
composer install
composer test        # PHPUnit: unit tests + incident fixtures
composer lint        # PHPCS with WordPress Coding Standards
composer analyse     # PHPStan
```

CI runs the same commands on PHP 7.4 to 8.5, plus the WordPress Plugin Check.

## Building the plugin

```bash
bash bin/build.sh
```

This copies only the runtime files into `build/earlyward/` (no tests, no Composer packages: the plugin
has no runtime dependencies) and refuses to finish if it finds a server path, a personal email address
or a private key in the output. Copy `build/earlyward` into a test site's `wp-content/plugins/`.

## Adding a check

1. Create `src/checks/<group>/class-<name>-check.php` implementing `Earlyward\Checks\Check`, and add
   it to `Scanner::with_default_checks()`.
2. Read only through the `Context` you are given (its filesystem, database and loopback); never call
   `file_get_contents()`, `$wpdb` or `wp_remote_get()` directly. This keeps checks read-only, limited
   to the site's own folders, and testable with fixtures.
3. Add at least one malicious and one benign case under `tests/fixtures/<check-id>-<case>/`, each
   with an `expected.json` listing exactly the findings the full scanner must return.
4. Explain in the class doc comment what it detects, the real case behind it, and the known false
   positives.

## Commits and pull requests

Small commits, each with passing tests. Describe what you measured, not what you expect.
