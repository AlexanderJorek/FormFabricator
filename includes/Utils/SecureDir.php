<?php

/**
 * Shared content for locking down plugin-private upload subdirectories.
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
 * .htaccess only blocks direct HTTP access on Apache/LiteSpeed (when AllowOverride permits it) —
 * Nginx, Caddy, and IIS never read it at all. Generator.php and Verificationpage.php each write
 * their own .htaccess into the "fabricator-secure-pdf" directory under wp-content/uploads/ (whose
 * subdirectories are pdf/, embed/, mpdf/, verfiles/, verimages/ and log/); this class adds the
 * IIS-equivalent (web.config) alongside it, so at least one of the two is honored on every common
 * stack. Nginx/Caddy admins still need their own server-level deny rule — no plugin can add that
 * from inside PHP — random filenames (already in place) are the remaining control there.
 */
class SecureDir
{
    // Same effect as the .htaccess written at each of these directories: deny all direct requests.
    // Built as concatenated single-quoted lines rather than HEREDOC/NOWDOC — see CLAUDE.md's
    // "No HEREDOC/NOWDOC" rule (WordPress.org's codesniffers can't verify escaping inside them).
    public const WEB_CONFIG = '<?xml version="1.0" encoding="UTF-8"?>'
        . "\n" .
        '<configuration>'
        . "\n" .
        '    <system.webServer>'
        . "\n" .
        '        <security>'
        . "\n" .
        '            <authorization>'
        . "\n" .
        '                <remove users="*" roles="" verbs="" />'
        . "\n" .
        '            </authorization>'
        . "\n" .
        '        </security>'
        . "\n" .
        '    </system.webServer>'
        . "\n" .
        '</configuration>';
}
