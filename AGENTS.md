# Rules for any AI assistant working on Earlyward (read this first)

Earlyward is a free WordPress security-checking plugin (PHP 7.4+, GPL-2.0-or-later). A person (the owner) decides; one
orchestrator reviews and merges. You are a worker: you do ONE work order and stop.

## Hard rules
1. Do exactly the work order you were given. Nothing extra. If something else looks wrong, say so in your report; do not fix it.
2. Never commit, push, tag, release, deploy, or touch any live website. The orchestrator does all git work after reviewing your files.
3. Never read, copy or write secrets: `.env*`, `wp-config.php`, tokens, keys, passwords. Never print them.
4. Never write real site names, domains, user names or paths from private sources into any file. Use `site.fixture`, `example.com`.
5. Honest claims only. Never write "unhackable", "100%", "guaranteed" or "secure". Earlyward detects and warns; it does not promise.
6. No network calls from the plugin other than those listed in `docs/WRITES.md` ("Network"). No analytics, no phone-home, no external fonts or scripts on any web page you make.
7. Scans never write. Anything that changes files must go through `src/fix/` with a confirmation, be listed in `docs/WRITES.md`, and have tests. A work order that needs this must say so; otherwise stop and report.
8. Line endings are LF. Files are UTF-8. Edit with your file-editing tool, not with shell redirects.

## Where things are
- `src/checks/<area>/class-*-check.php` one class per check; `src/scanner/class-scanner.php` lists them.
- `src/env/` the only way checks touch the outside (Context, Filesystem, Database, Loopback). Checks never use raw `$wpdb` or `file_get_contents`.
- `src/fix/` the only place that writes. `tests/fixtures/<case>/` one folder per scenario with `expected.json`; `tests/unit/`.
- `README.md`, `readme.txt`, `docs/` the public documentation. Keep them true: update them when behaviour changes.

## Checks you must run before you report (all must pass)
Use PHP 8.3 (the orchestrator tells you the path in the work order). From the repository root:
```
php vendor/bin/phpunit
php vendor/bin/phpcs
php vendor/bin/phpstan analyse --memory-limit=1G
```
phpcs and phpstan must report no errors. If a check fails and you cannot fix it inside the work order, stop and report the exact output (last 20 lines). Do not weaken a test, a rule or an `expected.json` to make it pass.

## Traps already paid for
- A new check that fires on ordinary fixtures breaks every fixture: update `expected.json` deliberately, and add a benign fixture next to the bad one.
- Options read from the database arrive as incomplete-class objects: cast to array, never read properties.
- Windows Defender blocks test files that contain decode-and-run strings: use short, harmless stand-ins.
- In PHP single-quoted strings avoid apostrophes; use double quotes or rephrase.

## How you end (mandatory)
Reply with a `WHAT I DID` block (plain words, under 15 lines): the work order id; files changed (list); checks run with pass counts; anything left open or surprising. Then exactly this line:

**WORK ORDER COMPLETE. This thread is finished: close it. Start a NEW thread for the next work order.**

Keep the report short. Do not paste whole files or long logs.
