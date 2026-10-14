<?php

namespace SCMonitor\Support;

use SCMonitor\Settings\Settings;

/**
 * The list of distinct problems, one row each. This is what keeps
 * "the same fatal, every three hours, for nine days" from being nine
 * hundred lines: every log entry is reduced to a fingerprint, and
 * entries sharing one just bump that row's count and last-seen time.
 *
 * Stored in a single non-autoloaded option. It is capped, and rows
 * nobody has hit for `retention_days` are dropped, so it can't grow
 * without bound however noisy a site gets.
 *
 * Dismissing a row hides it; if the same problem is logged again
 * afterwards it comes back flagged as "returned" — a dismissed issue
 * that recurs is a fix that didn't work.
 */
final class IssueStore
{
    private const OPTION_KEY = 'scmonitor_issues';
    private const MAX_ISSUES = 150;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $issues = null;

    public function __construct(private Settings $settings)
    {
    }

    /**
     * @param list<array{time: int, type: string, severity: int, message: string, file: string, line: int, detail: string}> $entries
     */
    public function record(array $entries): void
    {
        $issues = $this->all();
        $now = \time();

        foreach ($entries as $entry) {
            $message = Paths::relative($entry['message']);
            $file = Paths::relative($entry['file']);
            $fingerprint = $this->fingerprint($entry['severity'], $message, $file, $entry['line']);
            $time = $entry['time'] > 0 ? $entry['time'] : $now;

            if (! isset($issues[$fingerprint])) {
                $issues[$fingerprint] = [
                    'type' => $entry['type'],
                    'severity' => $entry['severity'],
                    'message' => \mb_substr($message, 0, 400),
                    'file' => $file,
                    'line' => $entry['line'],
                    'detail' => \mb_substr(Paths::relative($entry['detail']), 0, 1500),
                    'count' => 0,
                    'first_seen' => $time,
                    'last_seen' => $time,
                    'dismissed_at' => 0,
                    'returned' => false,
                ];
            }

            $issue = &$issues[$fingerprint];
            $issue['count']++;
            $issue['last_seen'] = \max($issue['last_seen'], $time);

            if ($entry['detail'] !== '') {
                $issue['detail'] = \mb_substr(Paths::relative($entry['detail']), 0, 1500);
            }

            if ($issue['dismissed_at'] > 0 && $time > $issue['dismissed_at']) {
                $issue['dismissed_at'] = 0;
                $issue['returned'] = true;
            }

            unset($issue);
        }

        $this->save($this->prune($issues, $now));
    }

    /**
     * Issues that need a look: not dismissed, worst first, then most
     * recently seen.
     *
     * @return array<string, array<string, mixed>>
     */
    public function open(): array
    {
        $open = \array_filter($this->all(), static fn (array $issue): bool => $issue['dismissed_at'] === 0);

        \uasort($open, static fn (array $a, array $b): int => [$b['severity'], $b['last_seen']] <=> [$a['severity'], $a['last_seen']]);

        return $open;
    }

    public function dismiss(string $fingerprint): void
    {
        $issues = $this->all();

        if (isset($issues[$fingerprint])) {
            $issues[$fingerprint]['dismissed_at'] = \time();
            $issues[$fingerprint]['returned'] = false;
            $this->save($issues);
        }
    }

    public function dismissAll(): void
    {
        $issues = $this->all();
        $now = \time();

        foreach ($issues as &$issue) {
            if ($issue['dismissed_at'] === 0) {
                $issue['dismissed_at'] = $now;
                $issue['returned'] = false;
            }
        }

        unset($issue);

        $this->save($issues);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->issues === null) {
            $stored = \get_option(self::OPTION_KEY, []);
            $this->issues = \is_array($stored) ? $stored : [];
        }

        return $this->issues;
    }

    /**
     * Digits are masked so "line 355" vs "line 356" or "post 12" vs
     * "post 97" don't split one problem into many; the file and line
     * the error came from stay in, so two different bugs that happen to
     * share wording don't merge.
     */
    private function fingerprint(int $severity, string $message, string $file, int $line): string
    {
        return \md5($severity.'|'.\preg_replace('/\d+/', '#', $message).'|'.$file.'|'.$line);
    }

    /**
     * @param array<string, array<string, mixed>> $issues
     * @return array<string, array<string, mixed>>
     */
    private function prune(array $issues, int $now): array
    {
        $cutoff = $now - \max(1, (int) $this->settings->get('retention_days', 30)) * DAY_IN_SECONDS;

        $issues = \array_filter($issues, static fn (array $issue): bool => $issue['last_seen'] >= $cutoff);

        if (\count($issues) > self::MAX_ISSUES) {
            \uasort($issues, static fn (array $a, array $b): int => $b['last_seen'] <=> $a['last_seen']);
            $issues = \array_slice($issues, 0, self::MAX_ISSUES, true);
        }

        return $issues;
    }

    /**
     * @param array<string, array<string, mixed>> $issues
     */
    private function save(array $issues): void
    {
        $this->issues = $issues;

        // autoload = false: only the dashboard and the scanner want this.
        \update_option(self::OPTION_KEY, $issues, false);
    }
}
