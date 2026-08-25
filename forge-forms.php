<?php

/**
 * Plugin Name:       FormFabricator
 * Plugin URI:        https://github.com/AlexanderJorek/FormFabricator
 * Description:       Custom drag-and-drop form builder with PDF generation and email delivery.
 * Version:           1.0.4
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
define('FABRICATOR_FORMS_VERSION', '1.0.4');
define('FABRICATOR_FORMS_BASENAME', plugin_basename(__FILE__));

$fabricator_composer_autoload = FABRICATOR_FORMS_PATH . 'vendor/autoload.php';
if (!file_exists($fabricator_composer_autoload)) {
    add_action(
        'admin_notices',
        static function (): void {
            echo '<div class="notice notice-error"><p>'
            . '<strong>FormFabricator:</strong> '
            . esc_html__('Composer autoloader not found. Run <code>composer install</code> in the plugin directory.', 'formfabricator')
            . '</p></div>';
        }
    );
    // Bail out of the rest of the bootstrap — Plugin.php and its dependents rely on
    // Composer-vendored classes (e.g. Smalot\PdfParser\Parser), so continuing without
    // the autoloader would produce an uncaught fatal instead of staying inert.
    return;
}
include_once $fabricator_composer_autoload;

// Plugin.php is loaded explicitly (not via the Composer PSR-4 autoloader, which only
// covers vendor/ dependencies) since it's the class that wires up autoloading for the
// rest of includes/ and must exist before anything else in this plugin can run.
require_once FABRICATOR_FORMS_PATH . 'includes/Plugin.php';

// Deferred to plugins_loaded so other plugins are ready before hooks register.
add_action(
    'plugins_loaded',
    static function (): void {
        \FabricatorForms\Plugin::init();
    }
);

// Prevent orphaned recurring cron events (the PDF-verifier and PDF-generator
// temp-file sweeps) from continuing to fire after the plugin is deactivated.
register_deactivation_hook(
    __FILE__,
    static function (): void {
        wp_clear_scheduled_hook('fabricator_verifier_sweep_tmp_dirs');
        wp_clear_scheduled_hook('fabricator_generator_sweep_tmp_dirs');
    }
);
