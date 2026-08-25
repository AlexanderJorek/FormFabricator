<?php

/**
 * Plugin Name:       FormFabricator
 * Plugin URI:        https://github.com/AlexanderJorek/FormFabricator
 * Description:       Custom drag-and-drop form builder with PDF generation and email delivery.
 * Version:           1.0.3
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Alexander Jorek
 * Author URI:        https://github.com/AlexanderJorek
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       formfabricator
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

define('FORGE_FORMS_PATH', plugin_dir_path(__FILE__));
define('FORGE_FORMS_URL', plugin_dir_url(__FILE__));
define('FORGE_FORMS_VERSION', '1.0.3');
define('FORGE_FORMS_BASENAME', plugin_basename(__FILE__));

$forge_composer_autoload = FORGE_FORMS_PATH . 'vendor/autoload.php';
if (!file_exists($forge_composer_autoload)) {
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
include_once $forge_composer_autoload;

// Plugin.php is loaded explicitly (not via the Composer PSR-4 autoloader, which only
// covers vendor/ dependencies) since it's the class that wires up autoloading for the
// rest of includes/ and must exist before anything else in this plugin can run.
require_once FORGE_FORMS_PATH . 'includes/Plugin.php';

// German is reviewed and maintained by us directly in languages/. WordPress
// otherwise prefers a community-contributed translation from a WordPress.org
// language pack (wp-content/languages/plugins/formfabricator-de_DE.mo) over our
// own bundled file, if one happens to be installed. This filter forces our
// bundled, vetted German file to always win for de_DE specifically; every
// other locale (including English, which needs no file — it's the source
// language) is left to WordPress's normal resolution.
add_filter(
    'load_textdomain_mofile',
    static function (string $mofile, string $domain): string {
        if ($domain === 'formfabricator' && str_ends_with($mofile, '-de_DE.mo')) {
            $bundled = FORGE_FORMS_PATH . 'languages/formfabricator-de_DE.mo';
            if (file_exists($bundled)) {
                return $bundled;
            }
        }
        return $mofile;
    },
    10,
    2
);

// Deferred to plugins_loaded so translations/other plugins are ready before hooks register
add_action(
    'plugins_loaded',
    static function (): void {
        // Loads languages/formfabricator-{locale}.mo, if one exists for the site's configured
        // language, for our own bundled translations. WordPress.org's auto-loading of
        // translations only applies to translations submitted through translate.wordpress.org
        // for a plugin hosted there; this plugin bundles its own .mo files directly (see
        // languages/ and the load_textdomain_mofile filter above, which forces our bundled
        // de_DE.mo to win over any community translation). The manual load_plugin_textdomain()
        // call is still required for those bundled files to be found at all — removing it
        // would break German translations that ship with the plugin.
        // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- see comment above
        load_plugin_textdomain('formfabricator', false, dirname(FORGE_FORMS_BASENAME) . '/languages');
        \ForgeForms\Plugin::init();
    }
);

// Prevent orphaned recurring cron events (the PDF-verifier and PDF-generator
// temp-file sweeps) from continuing to fire after the plugin is deactivated.
register_deactivation_hook(
    __FILE__,
    static function (): void {
        wp_clear_scheduled_hook('forge_verifier_sweep_tmp_dirs');
        wp_clear_scheduled_hook('forge_generator_sweep_tmp_dirs');
    }
);
