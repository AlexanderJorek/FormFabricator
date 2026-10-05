<?php

namespace FabricatorForms\Tests\Support;

/**
 * Just enough $wpdb for OptionMutex: its lock is an "INSERT IGNORE" row in the options table, broken when stale by a
 * "DELETE … CAST(option_value AS UNSIGNED) < now", and released by a "DELETE" of its own value. Any other query fails the test,
 * so a helper that starts issuing new SQL shows up here instead of silently passing.
 */
final class FakeWpdb
{
    public string $options = 'wp_options';

    public int $rows_affected = 0;

    /** @var string[] Every query run, for assertions about locking. */
    public array $queries = [];

    public function __construct(private FakeWordPress $wp)
    {
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function prepare(string $query, mixed ...$args): array
    {
        return [$query, $args];
    }

    /**
     * @param array{0: string, 1: array<int, mixed>} $prepared
     */
    public function query(array $prepared): int
    {
        [$query, $args] = $prepared;
        $this->queries[] = $query;
        $name = (string) $args[0];

        if (str_starts_with($query, 'INSERT IGNORE INTO wp_options')) {
            $this->rows_affected = array_key_exists($name, $this->wp->options) ? 0 : 1;
            if ($this->rows_affected === 1) {
                $this->wp->options[$name] = serialize((string) $args[1]);
            }
            return $this->rows_affected;
        }
        if (str_starts_with($query, 'DELETE FROM wp_options WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d')) {
            $stale = array_key_exists($name, $this->wp->options) && (int) unserialize($this->wp->options[$name]) < (int) $args[1];
            if ($stale) {
                unset($this->wp->options[$name]);
            }
            return $this->rows_affected = $stale ? 1 : 0;
        }
        if ($query === 'DELETE FROM wp_options WHERE option_name = %s AND option_value = %s') {
            $owned = array_key_exists($name, $this->wp->options) && unserialize($this->wp->options[$name]) === (string) $args[1];
            if ($owned) {
                unset($this->wp->options[$name]);
            }
            return $this->rows_affected = $owned ? 1 : 0;
        }
        throw new \LogicException('FakeWpdb: unexpected query ' . $query);
    }
}
