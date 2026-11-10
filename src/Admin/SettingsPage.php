<?php

namespace SCMonitor\Admin;

use SCMonitor\Contracts\Hookable;
use SCMonitor\Scanning\Scanner;
use SCMonitor\Settings\Settings;
use SCMonitor\Support\LogLocator;

/**
 * The SC Monitor menu page (top level, like the other SC plugins). A handful of fields over the one
 * scmonitor_settings option row; the monitor works with none of them
 * touched.
 */
final class SettingsPage implements Hookable
{
    public const PAGE_SLUG = 'scmonitor-settings';
    private const SAVE_ACTION = 'scmonitor_save_settings';

    public function __construct(private Settings $settings, private Scanner $scanner)
    {
    }

    public function register(): void
    {
        \add_action('admin_menu', [$this, 'registerMenu']);
        \add_action('admin_post_'.self::SAVE_ACTION, [$this, 'handleSave']);
    }

    public function registerMenu(): void
    {
        \add_menu_page(
            'SC Monitor',
            'SC Monitor',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage'],
            'dashicons-warning',
            94
        );

        // The first submenu item repeats the page, so the menu reads "SC Monitor → Settings".
        \add_submenu_page(
            self::PAGE_SLUG,
            'SC Monitor',
            'Settings',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function handleSave(): void
    {
        if (! \current_user_can('manage_options')) {
            \wp_die('You do not have permission to do this.');
        }

        \check_admin_referer(self::SAVE_ACTION);

        $data = \wp_unslash($_POST['scmonitor_settings'] ?? []);
        $severity = (int) ($data['min_severity'] ?? 2);

        $this->settings->update([
            'use_wp_cron' => ! empty($data['use_wp_cron']),
            'log_path' => \sanitize_text_field((string) ($data['log_path'] ?? '')),
            'min_severity' => \array_key_exists($severity, Settings::SEVERITIES) ? $severity : 2,
            'ignore_patterns' => \sanitize_textarea_field((string) ($data['ignore_patterns'] ?? '')),
            'retention_days' => \min(365, \max(1, (int) ($data['retention_days'] ?? 30))),
        ]);

        \add_settings_error('scmonitor_settings', 'settings-updated', 'Settings saved.', 'success');
        \set_transient('settings_errors', \get_settings_errors(), 30);

        \wp_safe_redirect(\admin_url('admin.php?page='.self::PAGE_SLUG.'&settings-updated=true'));
        exit;
    }

    public function renderPage(): void
    {
        if (! \current_user_can('manage_options')) {
            return;
        }

        $s = $this->settings;
        $found = (new LogLocator($s))->find();
        $lastScan = (int) ($this->scanner->state()['last_scan'] ?? 0);
        ?>
        <div class="wrap">
            <h1>SC Monitor</h1>

            <?php \settings_errors('scmonitor_settings'); ?>

            <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
                <?php \wp_nonce_field(self::SAVE_ACTION); ?>
                <input type="hidden" name="action" value="<?php echo \esc_attr(self::SAVE_ACTION); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Scheduling</th>
                        <td>
                            <label><input type="checkbox" name="scmonitor_settings[use_wp_cron]" value="1" <?php \checked($s->get('use_wp_cron')); ?>> Scan hourly using WP-Cron</label>
                            <p class="description">
                                On by default and needs no setup. WP-Cron only runs when the site gets a visit, so a quiet site is checked a little late — fine for most. Last scan:
                                <strong><?php echo $lastScan > 0 ? \esc_html(\human_time_diff($lastScan).' ago') : 'never'; ?></strong>.
                                For exact timing, run it from a real cron job instead (instructions below) and untick this.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="scmonitor_log_path">PHP error log</label></th>
                        <td>
                            <input type="text" id="scmonitor_log_path" name="scmonitor_settings[log_path]" value="<?php echo \esc_attr((string) $s->get('log_path')); ?>" class="large-text code" placeholder="Detected automatically">
                            <p class="description">
                                <?php if ($found !== null) : ?>
                                    Currently reading <code><?php echo \esc_html($found); ?></code>.
                                <?php else : ?>
                                    <strong>No readable log found.</strong>
                                <?php endif; ?>
                                Leave blank to detect it (the <code>error_log</code> PHP setting, then <code>php_errorlog</code> in the site root, then <code>wp-content/debug.log</code>). Enter a full server path to override.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="scmonitor_min_severity">Report</label></th>
                        <td>
                            <select id="scmonitor_min_severity" name="scmonitor_settings[min_severity]">
                                <?php foreach (Settings::SEVERITIES as $value => $label) : ?>
                                    <option value="<?php echo \esc_attr((string) $value); ?>" <?php \selected((int) $s->get('min_severity'), $value); ?>><?php echo \esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Deprecations and notices make up most of a typical log and rarely need action, so the default is warnings and fatal errors. Changing this only affects entries scanned from now on.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="scmonitor_ignore_patterns">Ignore</label></th>
                        <td>
                            <textarea id="scmonitor_ignore_patterns" name="scmonitor_settings[ignore_patterns]" rows="4" class="large-text code"><?php echo \esc_textarea((string) $s->get('ignore_patterns')); ?></textarea>
                            <p class="description">One per line. A log entry whose message, file or stack trace contains any of these is skipped. The default hides wp-cli's own deprecation noise.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="scmonitor_retention_days">Forget after</label></th>
                        <td>
                            <input type="number" id="scmonitor_retention_days" name="scmonitor_settings[retention_days]" value="<?php echo \esc_attr((string) $s->get('retention_days')); ?>" min="1" max="365" class="small-text"> days without it happening again
                        </td>
                    </tr>
                </table>

                <?php \submit_button('Save settings'); ?>
            </form>

            <?php $this->renderCronInstructions(); ?>
        </div>
        <?php
    }

    /**
     * How to run the scan from a server cron job rather than WP-Cron.
     * The command carries this site's real path so it can be pasted as is.
     */
    private function renderCronInstructions(): void
    {
        $path = \untrailingslashit(\ABSPATH);
        $command = \sprintf('cd %s && wp sc-monitor scan --quiet', $path);
        ?>
        <hr>
        <h2>Running it from a real cron job</h2>
        <p>
            A server cron job runs on the clock whether or not anyone visits the site, which makes it the more reliable
            choice (and it is the way to go on a quiet site). It is optional: the plugin works on WP-Cron alone.
        </p>
        <ol>
            <li>
                <strong>Create the cron job.</strong> On SiteGround that is <em>Site Tools → Devs → Cron Jobs → Create</em>.
                On other hosts, <code>crontab -e</code> or the control panel's cron screen. Set it to run every hour
                (<code>0 * * * *</code>) — more often is fine, an idle scan costs a file-size check.
            </li>
            <li>
                <strong>Use this command</strong> (this site's path is filled in):
                <p><input type="text" readonly class="large-text code" value="<?php echo \esc_attr($command); ?>" onclick="this.select()"></p>
                <p class="description">
                    If <code>wp</code> isn't found, give its full path — <code>/usr/local/bin/wp</code> on SiteGround. Without WP-CLI,
                    use <code>cd <?php echo \esc_html($path); ?> &amp;&amp; php -r "require 'wp-load.php'; SCMonitor\\Plugin::instance()->scanner()->scan();"</code>.
                </p>
            </li>
            <li>
                <strong>Untick "Scan hourly using WP-Cron" above</strong> and save, so the site doesn't scan twice. (Scanning twice is
                harmless — it remembers how far it has read — just wasteful.)
            </li>
            <li>
                <strong>Check it works:</strong> run the command once by hand, then reload the dashboard. "Last scan" in the SC Monitor widget
                and above should say a few seconds ago. <code>wp sc-monitor issues</code> lists what it found.
            </li>
        </ol>
        <p class="description">
            Cron runs WP-CLI as the same user as the site, so it can read the log the web server writes. WP-CLI adds
            its own deprecation lines to the log on newer PHP versions; they are ignored by default (see "Ignore" above).
        </p>
        <?php
    }
}
