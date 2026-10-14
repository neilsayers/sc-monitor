<?php

namespace SCMonitor;

use SCMonitor\Admin\DashboardWidget;
use SCMonitor\Admin\SettingsPage;
use SCMonitor\Cli\Commands;
use SCMonitor\Scanning\Scanner;
use SCMonitor\Scanning\Schedule;
use SCMonitor\Settings\Settings;
use SCMonitor\Support\IssueStore;
use SCMonitor\Support\LogLocator;
use SCMonitor\Support\LogParser;

/**
 * Composes the plugin's features and wires them into WordPress.
 *
 * To grow the plugin (a front-end 5xx watcher, a failed-login counter,
 * a disk-space check ...) write a class implementing Contracts\Hookable
 * and add it to the list in boot(). Anything worth showing should end
 * up in the same dashboard widget as an entry in the IssueStore.
 */
final class Plugin
{
    private static ?self $instance = null;

    private Settings $settings;

    private IssueStore $store;

    private Scanner $scanner;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->settings = new Settings();
        $this->store = new IssueStore($this->settings);
        $this->scanner = new Scanner($this->settings, new LogLocator($this->settings), new LogParser(), $this->store);
    }

    public function boot(): void
    {
        $features = [
            new Schedule($this->scanner, $this->settings),
            new DashboardWidget($this->settings, $this->store, $this->scanner),
            new SettingsPage($this->settings, $this->scanner),
            new Commands($this->store, $this->scanner),
        ];

        foreach ($features as $feature) {
            $feature->register();
        }
    }

    public function scanner(): Scanner
    {
        return $this->scanner;
    }

    public function store(): IssueStore
    {
        return $this->store;
    }
}
