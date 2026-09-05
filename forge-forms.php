<?php

/**
 * Plugin Name:       FormFabricator
 * Plugin URI:        https://github.com/AlexanderJorek/FormFabricator
 * Description:       Custom drag-and-drop form builder with PDF generation and email delivery.
 * Version:           1.0.5
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Alexander Jorek
 * Author URI:        https://github.com/AlexanderJorek
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       formfabricator
 */

defined('ABSPATH') || exit;

define('FABRICATOR_FORMS_PATH', plugin_dir_path(__FILE__));
define('FABRICATOR_FORMS_URL', plugin_dir_url(__FILE__));
define('FABRICATOR_FORMS_VERSION', '1.0.5');
define('FABRICATOR_FORMS_BASENAME', plugin_basename(__FILE__));

$fabricator_composer_autoload = FABRICATOR_FORMS_PATH . 'vendor/autoload.php';
if (!file_exists($fabricator_composer_autoload)) {
    add_action(
        'admin_notices',
        static function (): void {
            echo '<div class="notice notice-error"><p>'
            . '<strong>FormFabricator:</strong> '
            . wp_kses(
                __('Composer autoloader not found. Run <code>composer install</code> in the plugin directory.', 'formfabricator'),
                ['code' => []]
            )
            . '</p></div>';
        }
    );
    // Bail out — without the autoloader, Plugin.php's Composer-vendored deps would fatal.
    return;
}
include_once $fabricator_composer_autoload;

// Loaded explicitly (not via Composer's PSR-4 autoloader) since it wires up autoloading for the rest of includes/.
require_once FABRICATOR_FORMS_PATH . 'includes/Plugin.php';

// No load_plugin_textdomain() call: WP core auto-loads a plugin's .mo since 4.6, and calling it here would trigger a WP 6.7+ _doing_it_wrong() notice.
add_action(
    'plugins_loaded',
    static function (): void {
        \FabricatorForms\Plugin::init();
    }
);

// Prevent orphaned recurring cron sweeps from continuing to fire after deactivation.
register_deactivation_hook(
    __FILE__,
    static function (): void {
        foreach (\FabricatorForms\Plugin::CRON_HOOKS as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }
);
