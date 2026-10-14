<?php

namespace SCMonitor\Support;

use SCMonitor\Settings\Settings;

/**
 * Finds the PHP error log. Hosts disagree about where it lives — this
 * one (SiteGround) drops a php_errorlog file in the WordPress root,
 * others set error_log in php.ini, and WP_DEBUG_LOG writes
 * wp-content/debug.log — so this tries each in turn and takes the
 * first one that exists and can be read.
 */
final class LogLocator
{
    public function __construct(private Settings $settings)
    {
    }

    public function find(): ?string
    {
        foreach ($this->candidates() as $path) {
            if (\is_file($path) && \is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function candidates(): array
    {
        $candidates = [];

        $custom = \trim((string) $this->settings->get('log_path'));

        if ($custom !== '') {
            $candidates[] = $custom;
        }

        $ini = (string) \ini_get('error_log');

        if ($ini !== '' && $ini !== 'syslog') {
            $candidates[] = $ini;
        }

        $candidates[] = \ABSPATH.'php_errorlog';
        $candidates[] = \WP_CONTENT_DIR.'/debug.log';

        /**
         * Lets a site add or reorder candidate log files.
         *
         * @param list<string> $candidates
         */
        return \array_values(\array_unique(\array_filter((array) \apply_filters('scmonitor_log_paths', $candidates), 'is_string')));
    }
}
