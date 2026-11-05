<?php

/**
 * The plugin's logging functions. Required by formfabricator.php: no autoloader finds a function.
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

namespace FabricatorForms;

defined('ABSPATH') || exit;

/**
 * A visitor's file name as a log line may record it: a short hash and the extension, never the name, which often holds a
 * person's name (GDPR Art. 5(1)(c)). The hash still lets an admin match two lines about the same file.
 *
 * @param string $name File name as uploaded.
 * @return string E.g. "file #3f9a1c2e.pdf".
 */
function fabricator_log_file(string $name): string
{
    // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- pathinfo() only parses the name, no file access.
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return 'file #' . substr(hash('sha256', $name), 0, 8) . (preg_match('/^[a-z0-9]{1,10}$/', $ext) === 1 ? '.' . $ext : '');
}

/**
 * Logs a debug message when WP_DEBUG is enabled.
 *
 * @param string $message The message to log.
 */
function fabricator_log(string $message): void
{
    if (defined('WP_DEBUG') && WP_DEBUG) {
        // Gated on WP_DEBUG so this isn't leftover production debug code.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- see comment above
        error_log($message);
    }
}
