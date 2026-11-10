<?php

namespace SCMonitor\Scanning;

use SCMonitor\Contracts\Hookable;
use SCMonitor\Settings\Settings;

/**
 * The hourly WP-Cron event that runs a scan.
 *
 * Skipped when "Use WP-Cron" is off (a real server cron job runs
 * `wp sc-monitor scan` instead — see the SC Monitor page in the admin menu).
 *
 * Scheduled again on `init` if it's missing, not only on activation:
 * a plugin copied onto a server (rsync, FTP) and activated from the
 * command line, or a cron table that got cleared, would otherwise
 * leave the monitor silently never running — the one failure a
 * monitor can't be allowed to have.
 */
final class Schedule implements Hookable
{
    public const HOOK = 'scmonitor_scan';

    public function __construct(private Scanner $scanner, private Settings $settings)
    {
    }

    public function register(): void
    {
        \add_action(self::HOOK, [$this->scanner, 'scan']);
        \add_action('init', [$this, 'ensureScheduled']);
    }

    public function ensureScheduled(): void
    {
        $scheduled = \wp_next_scheduled(self::HOOK);

        if ($this->settings->get('use_wp_cron', true)) {
            if (! $scheduled) {
                \wp_schedule_event(\time() + 60, 'hourly', self::HOOK);
            }
        } elseif ($scheduled) {
            // A real cron job has taken over; don't scan twice.
            \wp_clear_scheduled_hook(self::HOOK);
        }
    }
}
