<?php

namespace FabricatorForms\Tests\Support;

use Brain\Monkey\Functions;
use FabricatorForms\Fields\FieldRegistry;

/**
 * The WordPress functions a field's render()/sanitize path calls, for rendering fields without WordPress.
 *
 * The escaping stubs take strings, so an array config value throws a TypeError as with the real ones. The visitor is
 * logged out, with no options set.
 */
final class FieldStubs
{
    public static function install(): void
    {
        $html = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        Functions\when('esc_attr')->alias($html);
        Functions\when('esc_html')->alias($html);
        Functions\when('esc_textarea')->alias($html);
        Functions\when('esc_url')->alias(static fn(string $s): string => $s);
        Functions\when('esc_url_raw')->alias(static fn($s): string => (string) $s);
        Functions\when('esc_attr__')->alias(static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_html__')->alias(static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
        Functions\when('__')->returnArg(1);
        Functions\when('_x')->returnArg(1);
        Functions\when('_n')->alias(static fn($single, $plural, $n) => (int) $n === 1 ? $single : $plural);
        Functions\when('wp_json_encode')->alias(static fn($v, $f = 0) => json_encode($v, $f));
        Functions\when('selected')->alias(static fn($a, $b = true, $echo = true) => (string) $a === (string) $b ? ' selected' : '');
        Functions\when('checked')->alias(static fn($a, $b = true, $echo = true) => (string) $a === (string) $b ? ' checked' : '');
        Functions\when('sanitize_key')->alias(static fn($k) => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)));
        Functions\when('sanitize_text_field')->alias(static fn($s) => is_array($s) || is_object($s) ? '' : trim(strip_tags((string) $s)));
        Functions\when('sanitize_textarea_field')->alias(static fn($s) => is_array($s) ? '' : (string) $s);
        Functions\when('sanitize_hex_color')->alias(static fn($c) => preg_match('/^#[0-9a-f]{3,6}$/i', (string) $c) ? $c : null);
        Functions\when('wp_kses')->alias(static fn($s) => strip_tags((string) $s, '<p><a><b><i><strong><em><br><span><div><ul><ol><li>'));
        Functions\when('wp_kses_post')->alias(static fn($s) => (string) $s);
        Functions\when('wp_kses_allowed_html')->justReturn([]);
        Functions\when('wp_strip_all_tags')->alias(static fn($s) => strip_tags((string) $s));
        Functions\when('wp_specialchars_decode')->alias(static fn($s) => htmlspecialchars_decode((string) $s, ENT_QUOTES));
        Functions\when('get_option')->alias(static fn($k, $d = false) => $d);
        Functions\when('home_url')->alias(static fn($p = '') => 'https://example.test' . $p);
        Functions\when('site_url')->alias(static fn($p = '') => 'https://example.test' . $p);
        Functions\when('admin_url')->alias(static fn($p = '') => 'https://example.test/wp-admin/' . $p);
        Functions\when('wp_enqueue_script_module')->justReturn(null);
        Functions\when('wp_parse_url')->alias(static fn($u, $c = -1) => parse_url((string) $u, $c));
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('wp_rand')->alias(static fn($min = 0, $max = 0) => random_int((int) $min, (int) $max ?: PHP_INT_MAX));
        Functions\when('get_locale')->justReturn('en_US');
        Functions\when('determine_locale')->justReturn('en_US');
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('wp_get_current_user')->alias(static fn() => (object) ['ID' => 0]);
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('user_can')->justReturn(false);
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('get_privacy_policy_url')->justReturn('');
        Functions\when('get_the_ID')->justReturn(0);
        Functions\when('get_post')->justReturn(null);
        Functions\when('get_the_title')->justReturn('');
        Functions\when('get_permalink')->justReturn('');
        Functions\when('get_the_author_meta')->justReturn('');
        Functions\when('get_post_field')->justReturn('');
        Functions\when('get_queried_object_id')->justReturn(0);
        Functions\when('in_the_loop')->justReturn(false);
        Functions\when('wp_enqueue_script')->justReturn(null);
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('wp_timezone_string')->justReturn('UTC');
        Functions\when('get_bloginfo')->justReturn('');
        Functions\when('number_format_i18n')->alias(static fn($n, $d = 0) => number_format((float) $n, (int) $d));
        Functions\when('current_time')->alias(static fn() => date('Y-m-d'));
        Functions\when('wp_date')->alias(static fn($f, $t = null) => date($f, $t ?? time()));
        Functions\when('wp_upload_dir')->alias(static fn() => ['basedir' => sys_get_temp_dir(), 'baseurl' => 'https://example.test/u']);
        Functions\when('wp_max_upload_size')->justReturn(64 * 1048576);
        Functions\when('wp_convert_hr_to_bytes')->alias(static fn($v) => (int) $v * 1048576);
        Functions\when('wp_is_ini_value_changeable')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
    }

    /**
     * Loads every field class and registers them, as Plugin::load() does in WordPress.
     *
     * @return array<string, class-string> Type slug => class.
     */
    public static function registry(): array
    {
        foreach (array_keys(FieldRegistry::FIELD_MAP) as $short) {
            class_exists('FabricatorForms\\Fields\\' . $short);
        }
        FieldRegistry::registerDefaults();
        return FieldRegistry::all();
    }
}
