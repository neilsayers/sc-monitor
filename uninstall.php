<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('scmonitor_settings');
delete_option('scmonitor_issues');
delete_option('scmonitor_state');

wp_clear_scheduled_hook('scmonitor_scan');
