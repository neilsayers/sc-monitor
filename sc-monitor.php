<?php

/**
 * Plugin Name:       SC Monitor
 * Plugin URI:        https://screencandy.co.uk
 * Description:       Watches the site's PHP error log and puts each distinct problem — once, with a count — in a dashboard widget. Small on purpose: no external service, no emails, no tracking.
 * Version:           0.2.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Neil Sayers
 * Author URI:        https://screencandy.co.uk
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sc-monitor
 */

namespace SCMonitor;

if (! defined('ABSPATH')) {
    exit;
}

define('SCMONITOR_VERSION', '0.2.0');
define('SCMONITOR_FILE', __FILE__);
define('SCMONITOR_PATH', \plugin_dir_path(__FILE__));
define('SCMONITOR_URL', \plugin_dir_url(__FILE__));

/**
 * Minimal PSR-4-style autoloader so this plugin has zero build step
 * or Composer dependency — it just needs to be copied into any site's
 * wp-content/plugins and activated. Same shape as SC SEO's, SC Events
 * Manager's and SC Maps' own autoloaders, deliberately — but this
 * plugin shares no code with any of them at runtime.
 */
\spl_autoload_register(function (string $class): void {
    $prefix = __NAMESPACE__.'\\';

    if (! \str_starts_with($class, $prefix)) {
        return;
    }

    $relative = \substr($class, \strlen($prefix));
    $path = SCMONITOR_PATH.'src/'.\str_replace('\\', '/', $relative).'.php';

    if (\is_file($path)) {
        require $path;
    }
});

\register_activation_hook(__FILE__, [Setup\Activator::class, 'activate']);
\register_deactivation_hook(__FILE__, [Setup\Activator::class, 'deactivate']);

Plugin::instance()->boot();
