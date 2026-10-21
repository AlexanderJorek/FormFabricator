<?php

/**
 * The privacy-policy text the plugin suggests, for WordPress's Privacy Policy Guide and the Settings page.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.1.1
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Admin;

defined('ABSPATH') || exit;

/**
 * What the plugin does with a visitor's data, as suggested privacy-policy text: registered with WordPress's Privacy
 * Policy Guide, and shown on the Settings page in each language a translation is installed for.
 */
class PrivacyPolicy
{
    /**
     * Locales available for the privacy-policy text: 'en', plus every locale with both a plugin translation in
     * WordPress's language folder and the core locale installed (switch_to_locale() refuses others).
     *
     * @return array<string,string> Locale code => human-readable language name.
     */
    public static function availableLanguages(): array
    {
        $langs     = ['en' => 'English'];
        $installed = get_available_languages();
        // WP_LANG_DIR/plugins is where WP.org language packs install.
        $dirs = [
            rtrim((string) WP_LANG_DIR, '/\\') . '/plugins',
        ];
        foreach ($dirs as $dir) {
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- WordPress's own language folders.
            foreach (glob($dir . '/formfabricator-*.mo') ?: [] as $path) {
                // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- basename() only parses the name, no file access.
                $is_plugin_mo = preg_match('/^formfabricator-([A-Za-z]{2,3}(?:_[A-Za-z]{2,4})?)\.mo$/', basename($path), $m) === 1;
                if ($is_plugin_mo && in_array($m[1], $installed, true)) {
                    $langs[$m[1]] = self::localeDisplayName($m[1]);
                }
            }
        }
        return $langs;
    }

    /**
     * The English name of a locale's language ("German" for de_DE), beside "English" in the list; the code itself
     * without the intl extension. No WordPress admin file is loaded for it.
     *
     * @param string $locale Locale code, e.g. 'de_DE'.
     */
    private static function localeDisplayName(string $locale): string
    {
        $name = class_exists('Locale') ? (string) \Locale::getDisplayLanguage($locale, 'en') : '';
        return $name !== '' && $name !== $locale ? $name : $locale;
    }

    /**
     * Raw privacy-policy paragraphs: what happens to a submission, and the one third-party data flow (Google reCAPTCHA).
     *
     * @return string[] [0] form submissions, [1] Google reCAPTCHA.
     */
    private static function paragraphs(): array
    {
        $paragraphs = [
            __('Contact forms', 'formfabricator') . "\n" . __(
                // phpcs:ignore Generic.Files.LineLength -- must be a single string literal for WordPress i18n tooling to extract it correctly, see WordPress.WP.I18n.NonSingularStringLiteralText
                "When you submit a form on this website, your entries are not stored in the website's database. They are processed only while your submission is being handled and are then sent by email, possibly together with a generated PDF document, to the recipients chosen by the website operator. While this happens, uploaded files and the PDF document are written to temporary files on the server, which are deleted once the emails have been sent. To limit spam and abuse, a one-way hash of your IP address is kept for a short time to count how often a form is submitted, and is then deleted automatically. If the website operator checks a PDF document with the plugin's verification tool, the uploaded copy is deleted from the server right after the check, or about 10 minutes after its last use if the check is not completed.",
                'formfabricator'
            ),
            __('Google reCAPTCHA', 'formfabricator') . "\n" . __(
                // phpcs:ignore Generic.Files.LineLength -- must be a single string literal for WordPress i18n tooling to extract it correctly, see WordPress.WP.I18n.NonSingularStringLiteralText
                "To prevent spam and fraudulent form submissions, we use Google reCAPTCHA. Among other things, the IP address, the CAPTCHA response, and other technical information are transmitted to Google and processed there to determine whether the input was made by a human. This may involve the transfer of personal data to the United States. For more information, please see Google's Privacy Policy.",
                'formfabricator'
            ),
        ];
        // Only a site that uses reCAPTCHA sends anything to Google: without a site key, no CAPTCHA can load.
        if (trim((string) get_option('fabricator_forms_recaptcha_site_key', '')) === '') {
            array_pop($paragraphs);
        }
        return $paragraphs;
    }

    /**
     * Registers the third-party disclosure with WordPress's Privacy Policy Guide (Settings > Privacy).
     *
     * @return void
     */
    public static function register(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content('FormFabricator', self::html());
    }

    /**
     * The suggested privacy-policy text as the Privacy Policy Guide shows and copies it.
     *
     * @return string
     */
    private static function html(): string
    {
        $html = '';
        foreach (self::paragraphs() as $paragraph) {
            // Each paragraph is "Heading\nBody" — render the heading as a sub-heading.
            $parts   = explode("\n", $paragraph, 2);
            $heading = $parts[0];
            $body    = $parts[1] ?? '';
            // A plain paragraph: WordPress's "Copy suggested policy text" leaves out anything marked
            // "privacy-policy-tutorial" (it is guidance for the site owner), which left only the two headings.
            $html   .= '<h3>' . esc_html($heading) . '</h3>'
                . '<p>' . esc_html($body) . '</p>';
        }
        return $html;
    }

    /**
     * Suggested privacy-policy text, rendered in $lang regardless of the site's current admin-UI locale.
     *
     * @param string $lang Locale code from availableLanguages().
     * @return string The disclosure paragraphs, blank-line separated.
     */
    public static function plainText(string $lang): string
    {
        return self::withPluginLocale(
            $lang,
            static fn(): string => implode("\n\n", self::paragraphs())
        );
    }

    /**
     * Runs $callback with this plugin's textdomain swapped to $locale, then restores it.
     *
     * @param string   $locale   Locale code, e.g. 'de_DE', or 'en' for the gettext source language (no
     *                           .mo to load).
     * @param callable $callback Produces the string once the locale is active.
     */
    private static function withPluginLocale(string $locale, callable $callback): string
    {
        // Uses WP's switch_to_locale()/restore_previous_locale() API rather than reaching into the $l10n global directly.
        // 'en' -> 'en_US': this plugin's gettext source language has no .mo, so translation falls through to the original msgids.
        $target = ($locale === 'en' || $locale === '') ? 'en_US' : $locale;
        if (!preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z]{2,4})?$/', $target)) {
            return $callback();
        }

        $switched = switch_to_locale($target);
        try {
            return $callback();
        } finally {
            // Only restore when the switch actually took effect; restoring otherwise would pop a
            // locale off the switcher's stack that this call never pushed.
            if ($switched) {
                restore_previous_locale();
            }
        }
    }
}
