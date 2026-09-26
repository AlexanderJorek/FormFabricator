<?php

/**
 * pdfparser's Parser, reading through GuardedRawDataParser.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.7
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\PDF;

defined('ABSPATH') || exit;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * The verifier's PDF reader: unpacks only what mPDF writes, within an allowance (see GuardedRawDataParser).
 */
class GuardedPdfParser extends Parser
{
    /**
     * The guarded raw reader, also held as the parent's reader.
     *
     * @var GuardedRawDataParser
     */
    private GuardedRawDataParser $guard;

    /**
     * Constructor.
     *
     * @param Config $config    pdfparser configuration.
     * @param int    $allowance Most bytes all streams together may unpack to.
     */
    public function __construct(Config $config, int $allowance)
    {
        parent::__construct([], $config);
        $this->guard         = new GuardedRawDataParser([], $config, $allowance);
        $this->rawDataParser = $this->guard;
    }

    /**
     * The streams left packed; see GuardedRawDataParser::refused().
     *
     * @return array<int, array{object: string, filters: string[], reason: string, type: string, subtype: string, width: int, height: int}>
     */
    public function refusedStreams(): array
    {
        return $this->guard->refused();
    }

    /**
     * Refused streams not listed in refusedStreams().
     *
     * @return int
     */
    public function unlistedRefusedCount(): int
    {
        return $this->guard->unrecordedCount();
    }
}
