<?php

namespace SCMonitor\Setup;

use SCMonitor\Scanning\Schedule;

final class Activator
{
    public static function activate(): void
    {
        if (! \wp_next_scheduled(Schedule::HOOK)) {
            \wp_schedule_event(\time() + 60, 'hourly', Schedule::HOOK);
        }
    }

    public static function deactivate(): void
    {
        \wp_clear_scheduled_hook(Schedule::HOOK);
    }
}
