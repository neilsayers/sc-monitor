=== SC Monitor ===
Contributors: screencandy
Tags: monitoring, error log, php errors, dashboard
Requires at least: 6.6
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Watches the PHP error log and shows each distinct problem — once, with a count — in a dashboard widget.

== Description ==

A small, quiet monitor. Every hour a WP-Cron job reads whatever has been added to the PHP error log since last time,
throws away the noise, and files what's left under a fingerprint. The same fatal logged nine hundred times is one row
that says "x900, first seen 9 days ago, last seen 2 hours ago" — not nine hundred lines.

* **Dashboard widget** (administrators only): open issues, worst first, with file and line, a stack trace to expand,
  and Dismiss / Dismiss all. A dismissed issue that is logged again comes back marked "Returned".
* **Incremental and capped**: it remembers how far it read, so an hourly pass costs a file-size check unless something
  was logged. The first pass reads only the last couple of megabytes.
* **Quiet by default**: reports warnings and fatal errors; deprecations and notices are hidden (changeable), and
  wp-cli's own deprecation noise is ignored.
* **No external service**: nothing leaves the site. No emails, no tracking, no account.
* **WP-CLI**: `wp sc-monitor scan` and `wp sc-monitor issues` show the same data over SSH.
* **Scheduling**: hourly via WP-Cron by default (no setup; runs when the site is visited). For exact timing run
  `wp sc-monitor scan` from a real cron job and untick "Scan hourly using WP-Cron" — step-by-step instructions, with the
  site's own path filled in, are on Settings → SC Monitor.

The log is found automatically (the `error_log` PHP setting, `php_errorlog` in the WordPress root, then
`wp-content/debug.log`); Settings → SC Monitor can override it. Add or reorder candidates with the
`scmonitor_log_paths` filter.

== Changelog ==

= 0.1.0 =
* First release: hourly incremental scan of the PHP error log, de-duplicated into a dashboard widget.
