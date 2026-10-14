<?php

namespace SCMonitor\Settings;

use SCMonitor\Support\LogParser;

/**
 * Reads/writes the plugin's single options-table row. Same shape as SC
 * SEO's Settings class, deliberately — but this plugin shares no code
 * with it.
 */
final class Settings
{
    private const OPTION_KEY = 'scmonitor_settings';

    /**
     * What the "Minimum severity" setting offers. Deprecations and
     * notices are the bulk of a typical PHP log (this host's wp-cli
     * alone writes two deprecations per command) and almost never
     * need a human, so the default stops at warnings.
     */
    public const SEVERITIES = [
        LogParser::FATAL => 'Fatal errors only',
        LogParser::WARNING => 'Warnings and fatal errors',
        LogParser::NOTICE => 'Notices, warnings and fatal errors',
        LogParser::DEPRECATED => 'Everything, including deprecations',
    ];

    private const DEFAULTS = [
        // WP-Cron is the default because it needs nothing set up, at the
        // price of only running when the site gets a visit. Turn this off
        // when a real server cron job runs `wp sc-monitor scan` instead.
        'use_wp_cron' => true,
        'log_path' => '',
        'min_severity' => LogParser::WARNING,
        // One per line; a log entry containing any of these is skipped.
        // wp-cli runs on PHP versions newer than its own vendored code
        // supports and floods the log with its own deprecations.
        'ignore_patterns' => '/usr/local/wp-cli/',
        'retention_days' => 30,
    ];

    private array $values;

    public function __construct()
    {
        $stored = \get_option(self::OPTION_KEY, []);
        $this->values = \is_array($stored) ? \array_merge(self::DEFAULTS, $stored) : self::DEFAULTS;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * @return list<string>
     */
    public function ignorePatterns(): array
    {
        $lines = \preg_split('/\R/', (string) $this->get('ignore_patterns')) ?: [];

        return \array_values(\array_filter(\array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
    }

    /**
     * Every field is already sanitized by the caller (Admin\SettingsPage).
     *
     * @param array<string, mixed> $data
     */
    public function update(array $data): void
    {
        $this->values = \array_merge($this->values, $data);

        \update_option(self::OPTION_KEY, $this->values);
    }
}
