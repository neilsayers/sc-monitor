<?php

namespace SCMonitor\Support;

/**
 * Server paths in a log differ from what WordPress reports for the
 * same file (this host logs /home/customer/www/... but ABSPATH is
 * /home/<account>/www/...), and they're noise in a dashboard either
 * way. Trims everything before wp-content/, wp-includes/ or wp-admin/.
 */
final class Paths
{
    public static function relative(string $text): string
    {
        return (string) \preg_replace('#[^\s"\'(]*?/((?:wp-content|wp-includes|wp-admin)/)#', '$1', $text);
    }
}
