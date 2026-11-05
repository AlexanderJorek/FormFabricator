<?php

namespace FabricatorForms\Tests\Unit\Fields;

use FabricatorForms\Tests\Support\TestCase;

/**
 * A rule that draws a background image (a checkmark, a chevron) sets its colour with background-color, never with the
 * background shorthand: a dark mode (a theme's, a plugin's or a browser extension's) overrides colours with a copy of
 * the rule, and a copied shorthand resets the image as well, so the checkmark disappears.
 */
final class BackgroundImageCssTest extends TestCase
{
    public function testARuleWithABackgroundImageNeverUsesTheShorthand(): void
    {
        $root  = dirname(__DIR__, 3) . '/assets/css';
        $files = array_merge(glob($root . '/*.css') ?: [], glob($root . '/fields/*.css') ?: []);
        self::assertNotEmpty($files, 'the stylesheets are found');

        $offending = [];
        foreach ($files as $file) {
            $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file));
            // Innermost rules only, so a rule inside @media is read on its own.
            preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
            foreach ($rules as [, $selector, $body]) {
                if (preg_match('/(?:^|;)\s*background-image\s*:/i', $body) === 1 && preg_match('/(?:^|;)\s*background\s*:/i', $body) === 1) {
                    $offending[] = basename($file) . ': ' . trim($selector);
                }
            }
        }

        self::assertSame([], $offending);
    }
}
