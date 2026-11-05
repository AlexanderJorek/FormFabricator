<?php

/**
 * The one way the plugin records when something was signed or agreed to.
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

namespace FabricatorForms\Utils\Text;

defined('ABSPATH') || exit;

/**
 * Signatures, mandates, consents and privacy acknowledgments all record their moment alike: the date in the site's
 * format, then the time, both in UTC so they name one moment ("15. Oktober 2026, 22:30:00 UTC"). The server's clock
 * when the form is sent; the visitor's proves nothing.
 *
 * The day must be unambiguous (GDPR Art. 7(1)), so a format lacking the year, month or day gives way to Y-m-d.
 */
final class SigningTime
{
    /**
     * Dates a format must tell from the first to name a day (UTC): another year, one with the same calendar (2007's is
     * 2001's), another month, the next day, and the same weekday a week later.
     *
     * @var string[]
     */
    private const PROBE_DATES = ['2001-02-03', '2002-02-03', '2007-02-03', '2001-03-03', '2001-02-04', '2001-02-10'];

    /**
     * A moment, now by default, as a signature or an agreement records it.
     *
     * @param int|null $timestamp Unix time; now when null.
     * @return string
     */
    public static function format(?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $utc       = new \DateTimeZone('UTC');
        return sprintf(
            /* translators: 1: date in the site's date format, 2: time as hours:minutes:seconds. Both are UTC. */
            __('%1$s, %2$s UTC', 'formfabricator'),
            (string) wp_date(self::dateFormat(), $timestamp, $utc),
            (string) wp_date('H:i:s', $timestamp, $utc)
        );
    }

    /**
     * The site's date format when it names the year, the month and the day, else Y-m-d. Tested by what it writes, not
     * by its letters, so escaped characters ("\Y") and whole-date characters ("c", "U") count as what they write.
     *
     * @return string
     */
    private static function dateFormat(): string
    {
        $format = (string) get_option('date_format', '');
        if ($format === '') {
            return 'Y-m-d';
        }
        $utc   = new \DateTimeZone('UTC');
        $first = null;
        foreach (self::PROBE_DATES as $date) {
            $written = (string) wp_date($format, (int) strtotime($date . ' 12:00:00 UTC'), $utc);
            if ($first === null) {
                $first = $written;
            } elseif ($written === $first) {
                return 'Y-m-d';
            }
        }
        return $format;
    }
}
