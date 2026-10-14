<?php

namespace SCMonitor\Admin;

use SCMonitor\Contracts\Hookable;
use SCMonitor\Scanning\Scanner;
use SCMonitor\Settings\Settings;
use SCMonitor\Support\IssueStore;
use SCMonitor\Support\LogParser;

/**
 * The dashboard widget: every open issue once, worst first, with a
 * count and when it was first/last seen. Dismissing hides a row until
 * the same problem is logged again. Nothing here scans the log — that
 * is the cron job's work; "Scan now" just runs it on demand.
 */
final class DashboardWidget implements Hookable
{
    private const ACTION_DISMISS = 'scmonitor_dismiss';
    private const ACTION_DISMISS_ALL = 'scmonitor_dismiss_all';
    private const ACTION_SCAN = 'scmonitor_scan_now';

    private const SEVERITY_LABELS = [
        LogParser::FATAL => ['fatal', 'Fatal'],
        LogParser::WARNING => ['warning', 'Warning'],
        LogParser::NOTICE => ['notice', 'Notice'],
        LogParser::DEPRECATED => ['deprecated', 'Deprecated'],
    ];

    public function __construct(private Settings $settings, private IssueStore $store, private Scanner $scanner)
    {
    }

    public function register(): void
    {
        \add_action('wp_dashboard_setup', [$this, 'addWidget']);
        \add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        \add_action('admin_post_'.self::ACTION_DISMISS, [$this, 'handleDismiss']);
        \add_action('admin_post_'.self::ACTION_DISMISS_ALL, [$this, 'handleDismissAll']);
        \add_action('admin_post_'.self::ACTION_SCAN, [$this, 'handleScan']);
    }

    public function addWidget(): void
    {
        if (! \current_user_can('manage_options')) {
            return;
        }

        \wp_add_dashboard_widget('scmonitor_widget', $this->title(), [$this, 'render']);
    }

    /**
     * The count in the title is what you see before opening anything.
     */
    private function title(): string
    {
        $open = \count($this->store->open());

        return $open > 0 ? \sprintf('SC Monitor — %d open issue%s', $open, $open === 1 ? '' : 's') : 'SC Monitor';
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook === 'index.php') {
            \wp_enqueue_style('scmonitor-admin', SCMONITOR_URL.'assets/css/admin.css', [], SCMONITOR_VERSION);
        }
    }

    public function render(): void
    {
        $state = $this->scanner->state();
        $issues = $this->store->open();
        $lastScan = (int) ($state['last_scan'] ?? 0);
        ?>
        <div class="scmonitor">
            <p class="scmonitor__status">
                <?php if ($lastScan === 0) : ?>
                    Not scanned yet — the first scan runs within the hour, or scan now.
                <?php elseif (empty($state['log_found'])) : ?>
                    <strong>No PHP error log found.</strong> Set its location under Settings → SC Monitor, or turn on <code>WP_DEBUG_LOG</code>.
                <?php else : ?>
                    Last scan <?php echo \esc_html(\human_time_diff($lastScan)); ?> ago ·
                    <code><?php echo \esc_html(\basename((string) ($state['path'] ?? ''))); ?></code>
                    (<?php echo \esc_html(\size_format((int) ($state['size'] ?? 0))); ?>) ·
                    showing <?php echo \esc_html($this->severityLabel()); ?>
                <?php endif; ?>
            </p>

            <?php if ($issues === [] && $lastScan !== 0 && ! empty($state['log_found'])) : ?>
                <p class="scmonitor__clear">Nothing to look at. No open issues in the PHP error log.</p>
            <?php endif; ?>

            <?php if ($issues !== []) : ?>
                <ul class="scmonitor__list">
                    <?php foreach ($issues as $fingerprint => $issue) : ?>
                        <?php $this->renderIssue((string) $fingerprint, $issue); ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p class="scmonitor__actions">
                <a class="button" href="<?php echo \esc_url($this->actionUrl(self::ACTION_SCAN)); ?>">Scan now</a>
                <?php if ($issues !== []) : ?>
                    <a class="button" href="<?php echo \esc_url($this->actionUrl(self::ACTION_DISMISS_ALL)); ?>">Dismiss all</a>
                <?php endif; ?>
                <a href="<?php echo \esc_url(\admin_url('options-general.php?page=scmonitor-settings')); ?>">Settings</a>
            </p>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function renderIssue(string $fingerprint, array $issue): void
    {
        [$class, $label] = self::SEVERITY_LABELS[$issue['severity']] ?? ['notice', 'Notice'];
        $isNew = $issue['first_seen'] > \time() - DAY_IN_SECONDS;
        $where = $issue['file'] !== '' ? $issue['file'].($issue['line'] > 0 ? ':'.$issue['line'] : '') : '';
        ?>
        <li class="scmonitor__issue scmonitor__issue--<?php echo \esc_attr($class); ?>">
            <div class="scmonitor__head">
                <span class="scmonitor__badge scmonitor__badge--<?php echo \esc_attr($class); ?>"><?php echo \esc_html($label); ?></span>
                <?php if (! empty($issue['returned'])) : ?>
                    <span class="scmonitor__flag">Returned</span>
                <?php elseif ($isNew) : ?>
                    <span class="scmonitor__flag">New</span>
                <?php endif; ?>
                <span class="scmonitor__count">×<?php echo \esc_html(\number_format_i18n((int) $issue['count'])); ?></span>
            </div>
            <p class="scmonitor__message"><?php echo \esc_html($issue['message']); ?></p>
            <?php if ($where !== '') : ?>
                <p class="scmonitor__where"><code><?php echo \esc_html($where); ?></code></p>
            <?php endif; ?>
            <p class="scmonitor__meta">
                First seen <?php echo \esc_html(\human_time_diff((int) $issue['first_seen'])); ?> ago ·
                last seen <?php echo \esc_html(\human_time_diff((int) $issue['last_seen'])); ?> ago ·
                <a href="<?php echo \esc_url($this->actionUrl(self::ACTION_DISMISS, $fingerprint)); ?>">Dismiss</a>
            </p>
            <?php if ($issue['detail'] !== '') : ?>
                <details class="scmonitor__detail">
                    <summary>Details</summary>
                    <pre><?php echo \esc_html($issue['detail']); ?></pre>
                </details>
            <?php endif; ?>
        </li>
        <?php
    }

    private function severityLabel(): string
    {
        return match ((int) $this->settings->get('min_severity', LogParser::WARNING)) {
            LogParser::FATAL => 'fatal errors',
            LogParser::WARNING => 'warnings and above',
            LogParser::NOTICE => 'notices and above',
            default => 'everything',
        };
    }

    private function actionUrl(string $action, string $fingerprint = ''): string
    {
        $args = ['action' => $action];

        if ($fingerprint !== '') {
            $args['fp'] = $fingerprint;
        }

        return \wp_nonce_url(\add_query_arg($args, \admin_url('admin-post.php')), $action.$fingerprint);
    }

    private function guard(string $action, string $fingerprint = ''): void
    {
        if (! \current_user_can('manage_options')) {
            \wp_die('You do not have permission to do this.');
        }

        \check_admin_referer($action.$fingerprint);
    }

    private function back(): never
    {
        \wp_safe_redirect(\wp_get_referer() ?: \admin_url('index.php'));
        exit;
    }

    public function handleDismiss(): void
    {
        $fingerprint = \preg_replace('/[^a-f0-9]/', '', (string) ($_GET['fp'] ?? ''));

        $this->guard(self::ACTION_DISMISS, $fingerprint);
        $this->store->dismiss($fingerprint);
        $this->back();
    }

    public function handleDismissAll(): void
    {
        $this->guard(self::ACTION_DISMISS_ALL);
        $this->store->dismissAll();
        $this->back();
    }

    public function handleScan(): void
    {
        $this->guard(self::ACTION_SCAN);
        $this->scanner->scan();
        $this->back();
    }
}
