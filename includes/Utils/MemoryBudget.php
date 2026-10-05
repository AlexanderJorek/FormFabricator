<?php

/**
 * Host-aware memory budgeting for the plugin's expensive paths.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.8
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Utils;

defined('ABSPATH') || exit;

/**
 * Works out how much memory this host can spare and what a job costs: advisory bookkeeping, not an allocator.
 */
class MemoryBudget
{
    /**
     * Share of detected host memory the plugin will ever commit; the rest is for PHP, the DB, the web server and the OS.
     *
     * @var float
     */
    private const SAFETY_FRACTION = 0.6;

    /**
     * Floor for the derived budget, so detection reporting an implausibly small figure (an
     * aggressively capped container, a misparsed file) can't make every submission un-runnable.
     *
     * @var int
     */
    private const MIN_BUDGET_MB = 512;

    /**
     * Ceiling for the derived budget — past this, the bottleneck is CPU/wall-clock time, not memory, however large the host.
     *
     * @var int
     */
    private const MAX_BUDGET_MB = 8192;

    /**
     * Marginal cost only: WordPress's own baseline is already in use, and charging it here again would count it twice.
     * 256 MB, for example, would let a 1 GB container run only 2 submissions of a contact form without uploads at once.
     *
     * @var int
     */
    private const BASE_COST_MB = 96;

    /**
     * Jobs at or below this cost are admitted with no reservation — gating a no-upload submission behind shared accounting risks a 429 for nothing.
     *
     * @var int
     */
    private const RESERVATION_THRESHOLD_MB = 128;

    /**
     * Copies of an upload held at once (raw, base64, PDF stream, bitmap).
     *
     * @var int
     */
    private const PAYLOAD_FACTOR = 4;

    /**
     * Memory per byte of submitted text while mPDF lays it out (measured at about 500 on mPDF 8).
     *
     * @var int
     */
    private const TEXT_FACTOR = 512;

    /**
     * How long host-capacity detection is cached — capacity rarely changes, but an hour keeps a container resize from going unnoticed for long.
     *
     * @var int
     */
    private const DETECT_CACHE_TTL = 3600;

    /**
     * One bucket for every subsystem, since separate ones could together exceed the host.
     *
     * @var string
     */
    private const BUCKET = 'mem';

    /**
     * Hard cap on simultaneous holders, independent of bytes: a flood of tiny jobs that each fit
     * comfortably inside the budget still shouldn't run in unbounded numbers.
     *
     * @var int
     */
    private const MAX_HOLDERS = 24;

    /**
     * Per-request memo, so several calls in one submission don't repeat the transient lookup.
     *
     * @var int|null
     */
    private static ?int $budget_memo = null;

    /**
     * Reserves this job's estimated memory — wraps ConcurrencySlot so a caller can't accidentally open a private budget by typo'ing a bucket name.
     *
     * @param int  $estimated_bytes From estimateBytes().
     * @param int  $ttl_seconds     How long the reservation survives a caller that dies mid-job.
     * @param bool $always          Take a slot whatever the size, so the job also counts towards MAX_HOLDERS. For work
     *                              whose cost doesn't follow its input size — the PDF check parses the whole file — a
     *                              flood of small jobs would otherwise run in unbounded numbers, since every payload up
     *                              to 8 MB falls under RESERVATION_THRESHOLD_MB.
     * @return string|false Token for releaseReservation(), or false when the budget can't cover it.
     */
    public static function reserve(int $estimated_bytes, int $ttl_seconds, bool $always = false): string|false
    {
        // Small jobs skip accounting entirely (see RESERVATION_THRESHOLD_MB), unless the caller asks otherwise. The
        // sentinel token is accepted by releaseReservation() as a no-op, so callers need no special case.
        if (!$always && $estimated_bytes <= self::RESERVATION_THRESHOLD_MB * 1024 * 1024) {
            return self::UNRESERVED_TOKEN;
        }
        return ConcurrencySlot::reserve(
            self::BUCKET,
            $estimated_bytes,
            self::budgetBytes(),
            $ttl_seconds,
            self::MAX_HOLDERS
        );
    }

    /**
     * Token for jobs below RESERVATION_THRESHOLD_MB (no row held); never a valid ConcurrencySlot token.
     *
     * @var string
     */
    private const UNRESERVED_TOKEN = 'unreserved';

    /**
     * Releases a reservation taken by reserve().
     *
     * @param string $token The token reserve() returned.
     * @return void
     */
    public static function releaseReservation(string $token): void
    {
        if ($token === self::UNRESERVED_TOKEN || $token === '') {
            return;
        }
        ConcurrencySlot::release(self::BUCKET, $token);
    }

    /**
     * Bytes currently reserved across all holders, for diagnostic log lines.
     *
     * @return int
     */
    public static function reservedBytes(): int
    {
        return ConcurrencySlot::reservedBytes(self::BUCKET);
    }

    /**
     * Total bytes the plugin may commit to concurrent expensive work on this host.
     *
     * @return int Budget in bytes; always at least MIN_BUDGET_MB.
     */
    public static function budgetBytes(): int
    {
        if (self::$budget_memo !== null) {
            return self::$budget_memo;
        }

        // An explicit operator override wins outright, even over MAX_BUDGET_MB/SAFETY_FRACTION — it's a measured answer, not a guess to clamp.
        if (defined('FABRICATOR_MEMORY_BUDGET_MB')) {
            $override = (int) constant('FABRICATOR_MEMORY_BUDGET_MB');
            if ($override > 0) {
                self::$budget_memo = $override * 1024 * 1024;
                return self::$budget_memo;
            }
        }

        $detected = self::detectHostMemoryBytes();
        if ($detected !== null) {
            $budget_mb = (int) (($detected * self::SAFETY_FRACTION) / (1024 * 1024));
        } else {
            // Nothing to probe: PHP's own memory_limit.
            $php_limit = self::phpMemoryLimitBytes();
            $budget_mb = $php_limit > 0
                ? (int) ($php_limit / (1024 * 1024))
                : self::MIN_BUDGET_MB;
        }

        $budget_mb = max(self::MIN_BUDGET_MB, min(self::MAX_BUDGET_MB, $budget_mb));
        self::$budget_memo = $budget_mb * 1024 * 1024;
        return self::$budget_memo;
    }

    /**
     * Estimated peak memory for a job whose payload is $payload_bytes.
     *
     * @param int $payload_bytes Sum of the bytes this job will hold in memory: uploaded files and signature images for
     *                            a submission, the PDF's own size for a verification.
     * @param int $text_bytes    Submitted text that the PDF lays out (TEXT_FACTOR); 0 for a verification.
     * @return int Estimated peak in bytes.
     */
    public static function estimateBytes(int $payload_bytes, int $text_bytes = 0): int
    {
        $payload_bytes = max(0, $payload_bytes);
        $text_bytes    = max(0, $text_bytes);
        return (self::BASE_COST_MB * 1024 * 1024) + ($payload_bytes * self::PAYLOAD_FACTOR) + ($text_bytes * self::TEXT_FACTOR);
    }

    /**
     * Raises memory_limit to at least $bytes when the current limit is lower, targeting the job's own estimate.
     *
     * Through wp_raise_memory_limit() (Plugin Check rejects ini_set('memory_limit')), whose context filter carries the
     * estimate; it never lowers the limit, and PHP resets it after the request.
     *
     * @param int $bytes Target limit in bytes.
     * @return void
     */
    public static function raiseTo(int $bytes): void
    {
        $current = self::phpMemoryLimitBytes();
        // -1 is unlimited: already above any target.
        if ($current === -1 || $current >= $bytes) {
            return;
        }
        $target = (int) ceil($bytes / (1024 * 1024)) . 'M';
        $filter = static fn(): string => $target;
        add_filter('fabricator_forms_memory_limit', $filter);
        $raised = wp_raise_memory_limit('fabricator_forms');
        remove_filter('fabricator_forms_memory_limit', $filter);
        if ($raised === false || self::phpMemoryLimitBytes() < $bytes) {
            // Hosts that pin memory_limit (php_admin_value, disable_functions) refuse the raise; the job then
            // runs under the old limit, which is worth knowing when a large submission later dies of OOM.
            \FabricatorForms\fabricator_log(
                'FabricatorForms MemoryBudget: could not raise memory_limit from ' . (string) ini_get('memory_limit')
                . ' to ' . $target . '; the host does not allow changing it at runtime.'
            );
        }
    }

    /**
     * The largest total upload whose estimate, with $besides bytes more, still fits the whole budget. Upload fields
     * accept nothing larger.
     *
     * @param int $besides Memory needed on top of the files, such as a decoded image.
     * @return int Bytes.
     */
    public static function largestUploadBytes(int $besides = 0): int
    {
        return intdiv(max(0, self::budgetBytes() - self::BASE_COST_MB * 1024 * 1024 - $besides), self::PAYLOAD_FACTOR);
    }

    /**
     * Whether raiseTo() can raise the memory limit: wp_raise_memory_limit() gives up where the host fixes it.
     *
     * @return bool
     */
    public static function canRaiseLimit(): bool
    {
        return !function_exists('wp_is_ini_value_changeable') || wp_is_ini_value_changeable('memory_limit');
    }

    /**
     * Parses PHP's memory_limit ini setting into a byte count.
     *
     * @return int Byte count, or -1 when unlimited/unset.
     */
    public static function phpMemoryLimitBytes(): int
    {
        $val = trim((string) ini_get('memory_limit'));
        if ($val === '' || $val === '-1') {
            return -1;
        }
        $unit = strtolower(substr($val, -1));
        $num  = (int) $val;
        return match ($unit) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => (int) $val,
        };
    }

    /**
     * Best available host-memory reading, tried in descending trustworthiness — null (falling back to the floor) is a normal outcome, not an error.
     *
     * @return int|null Bytes, or null when nothing could be determined.
     */
    private static function detectHostMemoryBytes(): ?int
    {
        // FABRICATOR_MEMORY_BUDGET_MB is handled in budgetBytes(), before this is ever called:
        // it is a budget, not a capacity, and must bypass SAFETY_FRACTION and the clamp.
        $cached = get_transient('fabricator_host_memory_bytes');
        if (is_numeric($cached)) {
            return ((int) $cached) > 0 ? (int) $cached : null;
        }

        $detected = self::probeCgroupV2()
            ?? self::probeCgroupV1()
            ?? self::probeProcMeminfo();

        // Cache the miss too (as 0), so a host with none of these doesn't re-probe every request.
        set_transient('fabricator_host_memory_bytes', (string) ($detected ?? 0), self::DETECT_CACHE_TTL);
        return $detected;
    }

    /**
     * cgroup v2 container limit. The most accurate source on modern containerized hosting,
     * because it reports the container's share rather than the physical machine's total.
     *
     * @return int|null
     */
    private static function probeCgroupV2(): ?int
    {
        $raw = self::readSmallFile('/sys/fs/cgroup/memory.max');
        // Literal "max" means no container limit is set — fall through to the next probe.
        if ($raw === null || $raw === 'max') {
            return null;
        }
        $bytes = (int) $raw;
        return $bytes > 0 ? $bytes : null;
    }

    /**
     * cgroup v1 container limit. Unlimited is expressed as a sentinel near PHP_INT_MAX rather than
     * a keyword, so implausibly large readings are treated as "no limit set".
     *
     * @return int|null
     */
    private static function probeCgroupV1(): ?int
    {
        $raw = self::readSmallFile('/sys/fs/cgroup/memory/memory.limit_in_bytes');
        if ($raw === null) {
            return null;
        }
        $bytes = (int) $raw;
        // > 1TB is the conventional "unlimited" sentinel here, not a real allocation.
        if ($bytes <= 0 || $bytes > 1024 * 1024 * 1024 * 1024) {
            return null;
        }
        return $bytes;
    }

    /**
     * Host-level free memory, last resort; uses MemAvailable not MemFree since it accounts for reclaimable cache.
     *
     * @return int|null
     */
    private static function probeProcMeminfo(): ?int
    {
        $raw = self::readSmallFile('/proc/meminfo');
        if ($raw === null) {
            return null;
        }
        if (preg_match('/^MemAvailable:\s+(\d+)\s*kB/mi', $raw, $m)) {
            return ((int) $m[1]) * 1024;
        }
        if (preg_match('/^MemTotal:\s+(\d+)\s*kB/mi', $raw, $m)) {
            return ((int) $m[1]) * 1024;
        }
        return null;
    }

    /**
     * Reads a small kernel pseudo-file — WP_Filesystem is deliberately skipped since it may need credentials unavailable on a front-end request.
     *
     * @param string $path Absolute path to a kernel pseudo-file.
     * @return string|null Trimmed contents, or null if unreadable/empty/implausible.
     */
    private static function readSmallFile(string $path): ?string
    {
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- $path is always one of three hardcoded literals, never request input.
        if (!Cast::withoutWarnings(static fn(): bool => is_readable($path))) {
            return null;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- kernel pseudo-file, hardcoded path; see method docblock.
        $raw = Cast::withoutWarnings(static fn(): string|false => file_get_contents($path, false, null, 0, 65536));
        if ($raw === false) {
            return null;
        }
        $raw = trim($raw);
        return $raw === '' ? null : $raw;
    }
}
