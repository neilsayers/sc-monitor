<?php

namespace SCMonitor\Scanning;

use SCMonitor\Settings\Settings;
use SCMonitor\Support\IssueStore;
use SCMonitor\Support\LogLocator;
use SCMonitor\Support\LogParser;

/**
 * One pass over the PHP error log: read what's been added since last
 * time, drop the noise, hand the rest to the IssueStore.
 *
 * Incremental on purpose. The log on a busy host is tens of megabytes
 * and mostly deprecations, so each pass remembers how far it got
 * (byte offset + the file's inode, so a rotated log is spotted) and
 * reads only the new part. The very first pass, and any pass after a
 * long gap, reads just the tail — a recent window is what matters, not
 * the archaeology.
 */
final class Scanner
{
    public const STATE_KEY = 'scmonitor_state';

    private const FIRST_SCAN_BYTES = 2 * 1024 * 1024;
    private const MAX_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private Settings $settings,
        private LogLocator $locator,
        private LogParser $parser,
        private IssueStore $store
    ) {
    }

    /**
     * @return array{found: bool, path: string, size: int, bytes: int, entries: int, kept: int}
     */
    public function scan(): array
    {
        $summary = ['found' => false, 'path' => '', 'size' => 0, 'bytes' => 0, 'entries' => 0, 'kept' => 0];
        $state = $this->state();
        $path = $this->locator->find();

        if ($path === null) {
            $this->saveState(['log_found' => false, 'last_scan' => \time()] + $state);

            return $summary;
        }

        \clearstatcache(true, $path);

        $size = (int) \filesize($path);
        $inode = (int) \fileinode($path);
        $summary['found'] = true;
        $summary['path'] = $path;
        $summary['size'] = $size;

        $resume = ($state['path'] ?? '') === $path && ($state['inode'] ?? 0) === $inode && ($state['offset'] ?? PHP_INT_MAX) <= $size;
        $offset = $resume ? (int) $state['offset'] : \max(0, $size - self::FIRST_SCAN_BYTES);

        if ($size - $offset > self::MAX_BYTES) {
            $offset = $size - self::MAX_BYTES;
            $resume = false;
        }

        // Starting part-way through the file means the first line is
        // probably a fragment; skip to the next whole one.
        $skipFragment = ! $resume && $offset > 0;
        $newOffset = $offset;
        $entries = [];

        if ($size > $offset) {
            $raw = $this->read($path, $offset, $size - $offset);
            $start = 0;

            if ($skipFragment) {
                $firstBreak = \strpos($raw, "\n");
                $start = $firstBreak === false ? \strlen($raw) : $firstBreak + 1;
            }

            // Stop at the last complete line; a half-written entry is
            // picked up whole on the next pass.
            $end = \strrpos($raw, "\n");

            if ($end === false || $end < $start) {
                $newOffset = $offset + $start;
            } else {
                $text = \substr($raw, $start, $end - $start + 1);
                $newOffset = $offset + $end + 1;
                $summary['bytes'] = \strlen($text);
                $entries = $this->parser->parse($text);
            }
        }

        $summary['entries'] = \count($entries);
        $kept = $this->filter($entries);
        $summary['kept'] = \count($kept);

        if ($kept !== []) {
            $this->store->record($kept);
        }

        $this->saveState([
            'log_found' => true,
            'path' => $path,
            'inode' => $inode,
            'offset' => $newOffset,
            'size' => $size,
            'last_scan' => \time(),
        ]);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $state = \get_option(self::STATE_KEY, []);

        return \is_array($state) ? $state : [];
    }

    /**
     * @param list<array{time: int, type: string, severity: int, message: string, file: string, line: int, detail: string}> $entries
     * @return list<array{time: int, type: string, severity: int, message: string, file: string, line: int, detail: string}>
     */
    private function filter(array $entries): array
    {
        $min = (int) $this->settings->get('min_severity', LogParser::WARNING);
        $ignore = $this->settings->ignorePatterns();

        return \array_values(\array_filter($entries, static function (array $entry) use ($min, $ignore): bool {
            if ($entry['severity'] < $min) {
                return false;
            }

            $haystack = $entry['file'].' '.$entry['message'].' '.$entry['detail'];

            foreach ($ignore as $pattern) {
                if (\str_contains($haystack, $pattern)) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function read(string $path, int $offset, int $length): string
    {
        $handle = @\fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        \fseek($handle, $offset);
        $data = (string) \fread($handle, $length);
        \fclose($handle);

        return $data;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(array $state): void
    {
        \update_option(self::STATE_KEY, $state, false);
    }
}
