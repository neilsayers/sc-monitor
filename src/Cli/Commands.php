<?php

namespace SCMonitor\Cli;

use SCMonitor\Contracts\Hookable;
use SCMonitor\Scanning\Scanner;
use SCMonitor\Support\IssueStore;

/**
 * `wp sc-monitor scan` and `wp sc-monitor issues` — the same data the
 * dashboard widget shows, for checking a site over SSH without logging
 * in. Only registered when WP-CLI is running.
 */
final class Commands implements Hookable
{
    private const SEVERITY_NAMES = [3 => 'fatal', 2 => 'warning', 1 => 'notice', 0 => 'deprecated'];

    public function __construct(private IssueStore $store, private Scanner $scanner)
    {
    }

    public function register(): void
    {
        if (\defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::add_command('sc-monitor', $this);
        }
    }

    /**
     * Scans the PHP error log now.
     *
     * ## EXAMPLES
     *
     *     wp sc-monitor scan
     */
    public function scan(): void
    {
        $result = $this->scanner->scan();

        if (! $result['found']) {
            \WP_CLI::warning('No readable PHP error log found.');

            return;
        }

        \WP_CLI::success(\sprintf(
            'Read %s of %s: %d entries, %d worth keeping.',
            \size_format($result['bytes']) ?: '0 B',
            $result['path'],
            $result['entries'],
            $result['kept']
        ));
    }

    /**
     * Lists the open issues.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table (default), json, csv or yaml.
     *
     * ## EXAMPLES
     *
     *     wp sc-monitor issues
     *     wp sc-monitor issues --format=json
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function issues(array $args, array $assoc): void
    {
        $rows = [];

        foreach ($this->store->open() as $issue) {
            $rows[] = [
                'severity' => self::SEVERITY_NAMES[$issue['severity']] ?? 'notice',
                'count' => $issue['count'],
                'first_seen' => \gmdate('Y-m-d H:i', $issue['first_seen']),
                'last_seen' => \gmdate('Y-m-d H:i', $issue['last_seen']),
                'where' => $issue['file'].($issue['line'] > 0 ? ':'.$issue['line'] : ''),
                'message' => \mb_substr($issue['message'], 0, 140),
            ];
        }

        if ($rows === []) {
            \WP_CLI::success('No open issues.');

            return;
        }

        \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, \array_keys($rows[0]));
    }
}
