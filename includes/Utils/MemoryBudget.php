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
 * @version   1.0.6
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
 * Works out how much memory this host can spare, and what a given job will cost.
 *
 * Replaces the previous fixed "N slots x M megabytes" arithmetic. That bounded the worst case but
 * did it by guessing both numbers up front, before knowing either the host's capacity or the size
 * of the job — so it simultaneously refused work a large host could easily absorb and admitted
 * work a small host could not. Here the host's capacity is measured and each job is costed from
 * its actual payload, so a small submission reserves little (and many run side by side) while a
 * genuinely huge one is allowed to take the whole budget if nothing else is running.
 *
 * IMPORTANT — what a "reservation" is and is not: raising memory_limit does not allocate anything,
 * and PHP has no cross-process memory semaphore. These figures are advisory bookkeeping shared via
 * wp_options. They bound the work this plugin ADMITS concurrently; they cannot stop a process that
 * has already been admitted from exceeding its own estimate, and they know nothing about memory
 * consumed by the rest of the site. This is a safety rail, not an allocator.
 */
class MemoryBudget
{
    /**
     * Share of detected host memory the plugin will ever commit to concurrent expensive work.
     *
     * The rest is left for PHP-FPM's other workers, the database, the web server and the OS page
     * cache. Deliberately well under 1.0 — this plugin is a guest on the host, not its owner.
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
     * Ceiling for the derived budget. A very large host does not mean this plugin should feel
     * free to commit 200GB to concurrent PDF work; past this point the bottleneck is CPU and
     * wall-clock time, not memory.
     *
     * @var int
     */
    private const MAX_BUDGET_MB = 8192;

    /**
     * MARGINAL fixed cost of one job, on top of what an ordinary request already consumes.
     *
     * Deliberately not a whole worker's working set. WordPress, the object cache and PHP's own
     * baseline are already paid for by the host having spawned the process — charging them to the
     * reservation double-counts, and at the 256MB that figure was originally set to it capped an
     * ordinary no-upload contact form at 2 concurrent submissions on a 1GB container. 96MB
     * reflects what mPDF's parser and font subsystem actually add for a typical form.
     *
     * @var int
     */
    private const BASE_COST_MB = 96;

    /**
     * Jobs whose marginal cost is at or below this are admitted without a reservation at all.
     *
     * A submission with no uploads is no more expensive than any other WordPress request, and
     * gating those behind shared accounting buys nothing while risking a 429 on an ordinary
     * contact form. Admission control exists for jobs whose size actually varies.
     *
     * @var int
     */
    private const RESERVATION_THRESHOLD_MB = 128;

    /**
     * Multiplier applied to a job's payload bytes.
     *
     * Deliberately pessimistic. An upload is held as raw binary, again base64-encoded into the
     * mapped submission, again as an mPDF imageVar, and written to a temp file; a PDF being
     * verified is held raw, then again per decompressed stream, then again as a GD bitmap. Four
     * concurrent copies of the payload is the realistic ceiling for both paths.
     *
     * @var int
     */
    private const PAYLOAD_FACTOR = 4;

    /**
     * How long a successful host-capacity detection is cached. Total capacity is a property of the
     * machine, so it does not need re-reading per request; an hour keeps a container resize from
     * going unnoticed for long.
     *
     * @var int
     */
    private const DETECT_CACHE_TTL = 3600;

    /**
     * The single reservation bucket every expensive path shares.
     *
     * Deliberately one bucket, not one per subsystem: there is one host with one pool of RAM, so
     * a PDF verification running in wp-admin and a large public submission must compete for the
     * same budget. Separate buckets would each stay within their own limit while together
     * exceeding what the machine has — which is exactly the failure this replaced.
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
     * Reserves this job's estimated memory against the shared budget.
     *
     * Wrapping ConcurrencySlot here (rather than letting each caller name the bucket itself) is
     * what keeps every expensive path in the same pool — a caller cannot accidentally open a
     * private budget by typo'ing a bucket name.
     *
     * @param int $estimated_bytes From estimateBytes().
     * @param int $ttl_seconds     How long the reservation survives a caller that dies mid-job.
     * @return string|false Token for releaseReservation(), or false when the budget can't cover it.
     */
    public static function reserve(int $estimated_bytes, int $ttl_seconds): string|false
    {
        // Small jobs skip accounting entirely (see RESERVATION_THRESHOLD_MB). The sentinel token
        // is accepted by releaseReservation() as a no-op, so callers need no special case.
        if ($estimated_bytes <= self::RESERVATION_THRESHOLD_MB * 1024 * 1024) {
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
     * Token handed back for jobs below RESERVATION_THRESHOLD_MB, which hold no row.
     *
     * Not a valid ConcurrencySlot token (that is 24 hex chars from random_bytes(12)), so it can
     * never collide with a real reservation.
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

        /* An explicit operator instruction wins outright — including over MAX_BUDGET_MB and
           SAFETY_FRACTION. Someone who sets this has measured their own host, and clamping their
           answer with our heuristics would make the escape hatch useless on exactly the large
           machines that need it. */
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
            /* Nothing probe-able (Windows, open_basedir, a locked-down container). Fall back to
               PHP's own memory_limit, which is always readable and is a figure the host operator
               actually chose. Allowing concurrent work to total what a SINGLE request was already
               permitted is not a regression on any host — one job hitting the limit could always
               do that much — while still scaling with how the machine is provisioned instead of
               pinning every such host to the bare floor. */
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
     * @param int $payload_bytes Sum of the bytes this job will hold in memory: uploaded files for
     *                            a submission, the PDF's own size for a verification.
     * @return int Estimated peak in bytes.
     */
    public static function estimateBytes(int $payload_bytes): int
    {
        $payload_bytes = max(0, $payload_bytes);
        return (self::BASE_COST_MB * 1024 * 1024) + ($payload_bytes * self::PAYLOAD_FACTOR);
    }

    /**
     * Raises memory_limit to $bytes when the current limit is lower.
     *
     * Unlike the fixed raise this replaces, the target is the job's own estimate, so a small
     * submission is not handed a multi-gigabyte ceiling it has no use for.
     *
     * @param int $bytes Target limit in bytes.
     * @return string|null Previous memory_limit to hand back to restore(), or null if unchanged.
     */
    public static function raiseTo(int $bytes): ?string
    {
        $current = self::phpMemoryLimitBytes();
        // -1 is unlimited: already above any target, and setting a number would be a downgrade.
        if ($current === -1 || $current >= $bytes) {
            return null;
        }
        $previous = (string) ini_get('memory_limit');
        $target_mb = (int) ceil($bytes / (1024 * 1024));
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- bounded raise to this job's own reserved estimate, never more than budgetBytes(); restore() puts it back.
        @ini_set('memory_limit', $target_mb . 'M');
        return $previous;
    }

    /**
     * Restores a memory_limit captured by raiseTo(). No-op when it returned null.
     *
     * @param string|null $previous The value raiseTo() returned.
     * @return void
     */
    public static function restore(?string $previous): void
    {
        if ($previous === null || $previous === '') {
            return;
        }
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- restoring the value raised above, not a new global change.
        @ini_set('memory_limit', $previous);
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
     * Best available reading of how much memory this host actually has.
     *
     * Tried in descending order of trustworthiness. Every source is optional and every read is
     * guarded: open_basedir commonly blocks /proc and /sys, Windows has neither, and a shared host
     * may expose the physical machine's figures rather than the container's. Returning null (so
     * the caller falls back to the floor) is a normal outcome, not an error.
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
     * Host-level free memory. Last resort: on shared hosting this reports the whole machine, which
     * over-states what this site may actually use — hence SAFETY_FRACTION and MAX_BUDGET_MB.
     * MemAvailable (not MemFree) is used because it accounts for reclaimable page cache.
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
     * Reads a small pseudo-file, returning null on anything unexpected.
     *
     * WP_Filesystem is deliberately not used: these are kernel pseudo-files, not site content, and
     * WP_Filesystem may need FTP/SSH credentials that don't exist on a front-end request.
     *
     * @param string $path Absolute path to a kernel pseudo-file.
     * @return string|null Trimmed contents, or null if unreadable/empty/implausible.
     */
    private static function readSmallFile(string $path): ?string
    {
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- $path is only ever one of the three hardcoded literals in probeCgroupV2()/probeCgroupV1()/probeProcMeminfo(); never request-influenced.
        if (!@is_readable($path)) {
            return null;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- see method docblock: kernel pseudo-file, hardcoded path, WP_Filesystem is unavailable on the front-end path this runs on.
        $raw = @file_get_contents($path, false, null, 0, 65536);
        if ($raw === false) {
            return null;
        }
        $raw = trim($raw);
        return $raw === '' ? null : $raw;
    }
}
