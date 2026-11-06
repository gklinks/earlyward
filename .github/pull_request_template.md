## What and why

## How it was verified
<!-- Commands run and their result. "It parses" is not a test. -->

## Checklist
- [ ] Tests pass locally (`composer test`, `composer lint`, `composer analyse`)
- [ ] New or changed checks have a malicious AND a benign fixture
- [ ] Any new write is listed in `docs/WRITES.md`
- [ ] No network call beyond loopback without opt-in
- [ ] No real domains, paths, usernames, emails or keys in code, fixtures or docs
