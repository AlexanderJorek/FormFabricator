<?php

namespace FabricatorForms\Tests\Support;

use Brain\Monkey\Functions;

/**
 * An in-memory stand-in for the parts of WordPress that the plugin's stateful helpers use: options, transients, the
 * object cache, single cron events, the current user and a $wpdb that understands OptionMutex's lock rows.
 *
 * Values are serialized and scalars come back as strings, as from the database. Install with
 * FakeWordPress::install() in setUp(); Brain Monkey's tearDown removes the stubs.
 */
final class FakeWordPress
{
    /** @var array<string, string> Option name => serialized value. */
    public array $options = [];

    /** @var array<string, string> Transient name => serialized value. */
    public array $transients = [];

    /** @var array<string, array<string, mixed>> Group => key => value. */
    public array $cache = [];

    /** @var array<string, int> Hook => timestamp. */
    public array $cron = [];

    /** @var array<string, bool> Capabilities the current user holds. */
    public array $capabilities = ['manage_options' => true];

    public bool $nonceValid = true;

    public int $userId = 1;

    public string $uploadsDir = '';

    public FakeWpdb $wpdb;

    public static function install(): self
    {
        $wp       = new self();
        $wp->wpdb = new FakeWpdb($wp);
        $GLOBALS['wpdb'] = $wp->wpdb;

        Functions\when('get_option')->alias(static fn(string $name, mixed $default = false): mixed
            => array_key_exists($name, $wp->options) ? unserialize($wp->options[$name]) : $default);
        Functions\when('add_option')->alias(static function (string $name, mixed $value = '') use ($wp): bool {
            if (array_key_exists($name, $wp->options)) {
                return false;
            }
            $wp->options[$name] = self::store($value);
            return true;
        });
        Functions\when('update_option')->alias(static function (string $name, mixed $value) use ($wp): bool {
            $stored = self::store($value);
            if (($wp->options[$name] ?? null) === $stored) {
                return false; // WordPress reports "unchanged" as false
            }
            $wp->options[$name] = $stored;
            return true;
        });
        Functions\when('delete_option')->alias(static function (string $name) use ($wp): bool {
            $existed = array_key_exists($name, $wp->options);
            unset($wp->options[$name]);
            return $existed;
        });

        Functions\when('get_transient')->alias(static fn(string $name): mixed
            => array_key_exists($name, $wp->transients) ? unserialize($wp->transients[$name]) : false);
        Functions\when('set_transient')->alias(static function (string $name, mixed $value) use ($wp): bool {
            $wp->transients[$name] = self::store($value);
            return true;
        });
        Functions\when('delete_transient')->alias(static function (string $name) use ($wp): bool {
            $existed = array_key_exists($name, $wp->transients);
            unset($wp->transients[$name]);
            return $existed;
        });

        Functions\when('wp_cache_get')->alias(static fn(string $key, string $group = ''): mixed => $wp->cache[$group][$key] ?? false);
        Functions\when('wp_cache_set')->alias(static function (string $key, mixed $value, string $group = '') use ($wp): bool {
            $wp->cache[$group][$key] = $value;
            return true;
        });
        Functions\when('wp_cache_delete')->alias(static function (string $key, string $group = '') use ($wp): bool {
            unset($wp->cache[$group][$key]);
            return true;
        });

        Functions\when('wp_next_scheduled')->alias(static fn(string $hook): int|false => $wp->cron[$hook] ?? false);
        Functions\when('wp_schedule_single_event')->alias(static function (int $timestamp, string $hook) use ($wp): bool {
            $wp->cron[$hook] = $timestamp;
            return true;
        });
        Functions\when('wp_unschedule_event')->alias(static function (int $timestamp, string $hook) use ($wp): bool {
            unset($wp->cron[$hook]);
            return true;
        });

        Functions\when('current_user_can')->alias(static fn(string $cap): bool => !empty($wp->capabilities[$cap]));
        Functions\when('check_ajax_referer')->alias(static fn(): int|false => $wp->nonceValid ? 1 : false);
        Functions\when('get_current_user_id')->alias(static fn(): int => $wp->userId);
        Functions\when('wp_get_current_user')->alias(static fn(): object => (object) ['ID' => $wp->userId, 'user_login' => 'user' . $wp->userId]);

        Functions\when('wp_json_encode')->alias(static fn(mixed $data, int $flags = 0, int $depth = 512): string|false => json_encode($data, $flags, $depth));
        Functions\when('wp_upload_dir')->alias(static fn(): array => ['basedir' => $wp->uploadsDir, 'baseurl' => 'https://example.test/wp-content/uploads']);
        Functions\when('wp_delete_file')->alias(static function (string $file): void {
            if (is_file($file)) {
                unlink($file);
            }
        });

        return $wp;
    }

    /**
     * The value as the next request reads it back: scalars as strings, false as ''.
     */
    private static function store(mixed $value): string
    {
        if (is_scalar($value)) {
            $value = is_bool($value) ? ($value ? '1' : '') : (string) $value;
        }
        return serialize($value);
    }
}
