<?php

/**
 * mPDF subclass whose page-number aliases can't be triggered by submitted text.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.9
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\PDF;

defined('ABSPATH') || exit;

use Mpdf\Mpdf;

/**
 * mPDF that replaces only the per-document random page aliases Generator puts in the footer. Stock mPDF replaces
 * {PAGENO}, {nbpg} and the like in every page body, so a visitor typing "{nbpg}" would break the sealed text.
 *
 * Loaded on demand by Generator::generate(), so mPDF isn't pulled into every request.
 */
final class PageAliasMpdf extends Mpdf
{
    /**
     * Current-page alias; the total-page aliases live in mPDF's own aliasNbPg/aliasNbPgGp config.
     *
     * @var string
     */
    private string $pagenoAlias = '';

    /**
     * Sets the alias substituted with the current page number.
     *
     * @param string $alias Random token, e.g. "{fabpageno1a2b3c}".
     * @return void
     */
    public function setPagenoAlias(string $alias): void
    {
        $this->pagenoAlias = $alias;
    }

    /**
     * Replaces only this document's aliases, in plain (header/footer) and UTF-16BE (page body) form.
     *
     * @param string $html   Header/footer HTML or a page's content stream.
     * @param string $PAGENO Current page number string.
     * @param string $NbPgGp Page-group total string.
     * @param string $NbPg   Document total string.
     * @return string
     */
    // phpcs:ignore Squiz.Scope.MethodScope.Missing,PSR1.Methods.CamelCapsMethodName -- signature must match Mpdf::aliasReplace() exactly.
    protected function aliasReplace($html, $PAGENO, $NbPgGp, $NbPg)
    {
        $pairs = [
            [$this->pagenoAlias, (string) $PAGENO],
            [(string) $this->aliasNbPgGp, (string) $NbPgGp],
            [(string) $this->aliasNbPg, (string) $NbPg],
        ];
        foreach ($pairs as [$alias, $value]) {
            if ($alias === '') {
                continue;
            }
            $html = str_replace($alias, $value, $html);
            $html = str_replace(
                mb_convert_encoding($alias, 'UTF-16BE', 'UTF-8'),
                mb_convert_encoding($value, 'UTF-16BE', 'UTF-8'),
                $html
            );
        }
        return $html;
    }
}
