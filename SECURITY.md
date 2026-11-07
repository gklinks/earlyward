# Security policy

Earlyward runs inside WordPress with administrator-level reach, so a bug in it is a bug in your site.
We treat reports seriously.

## Reporting a vulnerability

**Please do not open a public issue.** Use GitHub's private reporting instead:
**Security tab -> "Report a vulnerability"** on this repository.

Include what you can: affected version, the steps or request that trigger it, and the impact you
expect. A proof of concept helps but is not required.

## What to expect

- An acknowledgement within 7 days.
- An assessment and, where it is a real issue, a fix plan within 30 days.
- Credit in the release notes if you want it.
- We ask that you wait for a fixed release, or 90 days from your report, before disclosing publicly.
  If a fix needs longer, we will tell you why.

These are the maintainers' targets for a volunteer project, not a contractual SLA.

## Supported versions

Until 1.0, only the latest release gets security fixes. After 1.0, the latest minor release.

## In scope

- Anything that lets a non-administrator read findings, trigger scans, change settings or run
  account actions.
- Missing capability checks, nonces, escaping or prepared statements.
- Path traversal or any user-controlled path reaching the filesystem layer.
- Any network call the plugin makes without the site owner opting in.
- A way for an attacker to hide from a check by crafting input that crashes or stalls it.

## Out of scope

- Malware that a check does not detect. That is a detection gap; please open a normal issue with a
  **sanitised** sample description (never post live credentials or someone else's site).
- Findings that need an attacker who already has administrator access or can write PHP files.
