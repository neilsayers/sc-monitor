<?php

namespace SCMonitor\Support;

/**
 * Turns a chunk of PHP error log text into entries.
 *
 * A log entry starts with a "[14-Oct-2026 13:17:39 UTC] " line; any
 * lines that follow before the next one belong to it (a stack trace,
 * mostly). Only the first line becomes the message — everything else is
 * kept as `detail`, capped, so one runaway trace can't fill the option
 * row.
 */
final class LogParser
{
    public const FATAL = 3;
    public const WARNING = 2;
    public const NOTICE = 1;
    public const DEPRECATED = 0;

    private const TYPES = [
        'fatal error' => self::FATAL,
        'parse error' => self::FATAL,
        'core error' => self::FATAL,
        'compile error' => self::FATAL,
        'recoverable fatal error' => self::FATAL,
        'warning' => self::WARNING,
        'core warning' => self::WARNING,
        'compile warning' => self::WARNING,
        'user warning' => self::WARNING,
        'notice' => self::NOTICE,
        'user notice' => self::NOTICE,
        'strict standards' => self::NOTICE,
        'deprecated' => self::DEPRECATED,
        'user deprecated' => self::DEPRECATED,
    ];

    private const MAX_LINE = 2000;
    private const MAX_DETAIL_LINES = 14;

    /**
     * @return list<array{time: int, type: string, severity: int, message: string, file: string, line: int, detail: string}>
     */
    public function parse(string $chunk): array
    {
        $entries = [];
        $current = null;

        foreach (\preg_split('/\R/', $chunk) ?: [] as $line) {
            if (\preg_match('/^\[(\d{1,2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2})(?: ([A-Za-z0-9_\/+:\-]+))?\] (.*)$/', $line, $m)) {
                if ($current !== null) {
                    $entries[] = $this->finish($current);
                }

                $current = ['time' => $this->time($m[1], $m[2] ?? ''), 'body' => \substr($m[3], 0, self::MAX_LINE), 'detail' => []];

                continue;
            }

            if ($current !== null && \trim($line) !== '' && \count($current['detail']) < self::MAX_DETAIL_LINES) {
                $current['detail'][] = \substr($line, 0, self::MAX_LINE);
            }
        }

        if ($current !== null) {
            $entries[] = $this->finish($current);
        }

        return $entries;
    }

    private function time(string $date, string $zone): int
    {
        try {
            return (new \DateTimeImmutable($date.' '.($zone !== '' ? $zone : 'UTC')))->getTimestamp();
        } catch (\Exception) {
            return 0;
        }
    }

    /**
     * @param array{time: int, body: string, detail: list<string>} $raw
     * @return array{time: int, type: string, severity: int, message: string, file: string, line: int, detail: string}
     */
    private function finish(array $raw): array
    {
        $body = $raw['body'];
        $detail = \implode("\n", $raw['detail']);

        if (\preg_match('/^PHP ([A-Za-z ]+?):\s+(.*)$/', $body, $m)) {
            $type = $m[1];
            $message = $m[2];
            $severity = self::TYPES[\strtolower($type)] ?? self::NOTICE;
        } else {
            // Not a PHP error: something a plugin wrote with error_log(),
            // or WordPress itself. Quiet by default, except database errors.
            $type = 'Log';
            $message = $body;
            $severity = \str_contains($body, 'WordPress database error') ? self::WARNING : self::NOTICE;
        }

        $file = '';
        $line = 0;

        // "message in /path/file.php on line 12" (warnings, notices) or
        // "... defined in /path/file.php:55" (the first line of an
        // uncaught exception).
        if (\preg_match('/^(.*) in (\/\S+?)(?: on line |:)(\d+)$/', $message, $m)) {
            $message = \preg_replace('/ and defined$/', '', $m[1]);
            $file = $m[2];
            $line = (int) $m[3];
        } elseif (\preg_match('/thrown in (\S+) on line (\d+)/', $detail, $m)) {
            $file = $m[1];
            $line = (int) $m[2];
        }

        return [
            'time' => $raw['time'],
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'detail' => $detail,
        ];
    }
}
