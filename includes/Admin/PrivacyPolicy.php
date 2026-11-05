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
     * The suggested text in each language there is one for: 'en', the source language, and every installed locale
     * WordPress loads a translation of this plugin for (switch_to_locale() refuses a locale that isn't installed).
     *
     * A locale is offered for what it reads like, not for having a file: one whose text reads exactly like a language
     * already listed adds nothing to choose from and is left out, and one sharing its language's name with another
     * entry is named with its region.
     *
     * @return array<string, array{name: string, text: string}> Locale code => display name and plain text.
     */
    public static function languages(): array
    {
        $langs = ['en' => ['name' => 'English', 'text' => self::plainText('en')]];
        // Asked of WordPress's own registry, which is what loads the translation once the locale is switched: a
        // language pack's .mo or .l10n.php, in WP_LANG_DIR/plugins or wherever a filter puts it.
        global $wp_textdomain_registry;
        if (!$wp_textdomain_registry instanceof \WP_Textdomain_Registry) {
            return $langs;
        }
        $seen = [$langs['en']['text'] => true];
        foreach (array_unique(get_available_languages()) as $locale) {
            if (!is_string($locale) || isset($langs[$locale]) || $wp_textdomain_registry->get('formfabricator', $locale) === false) {
                continue;
            }
            $text = self::plainText($locale);
            if (isset($seen[$text])) {
                continue;
            }
            $seen[$text]     = true;
            $langs[$locale] = ['name' => self::localeDisplayName($locale, false), 'text' => $text];
        }
        $names = array_count_values(array_column($langs, 'name'));
        foreach ($langs as $locale => $lang) {
            if ($locale !== 'en' && $names[$lang['name']] > 1) {
                $langs[$locale]['name'] = self::localeDisplayName($locale, true);
            }
        }
        return $langs;
    }

    /**
     * The English name of a locale's language ("German" for de_DE), beside "English" in the list; with its region
     * ("English (United Kingdom)") where the language alone would name two entries alike; the code itself without the
     * intl extension. No WordPress admin file is loaded for it.
     *
     * @param string $locale      Locale code, e.g. 'de_DE'.
     * @param bool   $with_region Whether to add the region.
     */
    private static function localeDisplayName(string $locale, bool $with_region): string
    {
        if (!class_exists('Locale')) {
            return $locale;
        }
        $name = (string) ($with_region ? \Locale::getDisplayName($locale, 'en') : \Locale::getDisplayLanguage($locale, 'en'));
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
        if (!self::coversRecaptcha()) {
            array_pop($paragraphs);
        }
        return $paragraphs;
    }

    /**
     * Whether the text covers Google reCAPTCHA: only a site with a reCAPTCHA site key sends anything to Google, since
     * without one no reCAPTCHA can load.
     *
     * @return bool
     */
    public static function coversRecaptcha(): bool
    {
        return trim((string) get_option('fabricator_forms_recaptcha_site_key', '')) !== '';
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
     * @param string $lang Locale code, 'en' or one installed (languages() lists those with a translation).
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
