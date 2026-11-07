# What a scan costs

These are measurements, not promises. They come from one small local test site, so they show the
shape of the cost (what dominates) more than a number you can expect on your site. Real-hosting and
large-site figures are not measured yet and are listed as such at the end.

## How to measure it on your own site

```sh
wp earlyward cost --runs=10            # table: median and slowest time per check, extra peak memory
wp earlyward cost --runs=10 --format=json
```

The command runs the full set of checks `--runs` times, stores nothing and sends nothing. Per-check
memory needs PHP 8.2 or newer (it uses `memory_reset_peak_usage()`); on older PHP the memory column
says so instead of guessing. The same figures per scan are included in `wp earlyward scan --format=json`
(`timings` in seconds, `memory` in bytes).

## What the scans run on

Scans run in WP-Cron (hourly) or WP-CLI, never on a visitor's page load. Opening the admin screen or
Site Health reads the stored result and does not scan.

## Measured on the local test site

| | |
|---|---|
| Date | 2026-11-07 |
| Earlyward | development build after 0.1.0 (14 checks; later checks not yet measured) |
| Machine | Windows 11, 32 GB RAM, local disk |
| PHP / WordPress | 8.3.36, command line / 7.1.4 with SQLite instead of MySQL |
| Site | 5 posts, 2 users, 9 plugins, about 3,600 files under `wp-content` (about 1,900 of them PHP) |
| Run from | WP-CLI, not the web server and not WP-Cron |

Ten full scans, one run of `wp earlyward cost --runs=10`:

| Check | Median | Slowest | Extra peak memory |
|---|---|---|---|
| A1 silent outage | 759 ms | 2133 ms | 2.2 MB |
| A2 crash markers | 1 ms | 1 ms | 2.7 KB |
| A3 error logs | 1 ms | 1 ms | 1.9 KB |
| A4 WP-Cron | 1 ms | 1 ms | 27.5 KB |
| C1 auto_prepend | 2 ms | 3 ms | 138.9 KB |
| C2 archive seeds | 3 ms | 4 ms | 4.5 KB |
| C3 unexpected PHP | 3 ms | 5 ms | 90.4 KB |
| C4 rogue admins | 4 ms | 10 ms | 61.2 KB |
| C5 admin credentials | 2 ms | 3 ms | 56.3 KB |
| C6 hidden user CSS | 2 ms | 3 ms | 27.3 KB |
| C9 cloaked links | 2 ms | 3 ms | 6.1 KB |
| B1 shared accounts | 2 ms | 3 ms | 2.2 KB |
| B2 open_basedir | 0 ms | 0 ms | 0 |
| B3 exposed backups | 4 ms | 6 ms | 3.1 KB |
| **Whole scan** | **787 ms** | **2161 ms** | process peak 61.4 MB, most of it WordPress itself |

A second run of the same command, minutes later, gave a median of 691 ms and a slowest of 757 ms for the
whole scan. The spread between runs is large for A1 and small for everything else.

## What this shows

- **A1 is almost all of the time.** It makes two real requests to the site's own homepage and REST
  index, so its cost is the site's own response time. A slow site makes the scan slow, mostly in
  waiting rather than computing. Everything else together took about 30 ms here.
- **Extra memory is small next to WordPress.** The largest check added 2.2 MB at its peak. In the second run, over a
  whole scan the process peak was 1.2 MB above where it started (WordPress itself accounts for the
  other roughly 63 MB).
- **Nothing here is a claim about your site.** The file and database checks grow with the number of
  files under `wp-content`, users and posts, and this site is small.

## Not measured yet

- Scans through WP-Cron or the web server (different PHP limits, cold opcache).
- Shared hosting, MySQL, and slow disks.
- Large sites: tens of thousands of files, thousands of posts or users.
- A site with a huge error log or many backups lying around.

These get filled in from the dogfood sites, and this page is updated with what is measured, with the
site size next to each number.
