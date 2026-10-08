<?php

namespace FabricatorForms\Tests\Unit\Utils;

use FabricatorForms\Tests\Support\TestCase;
use FabricatorForms\Utils\Assets;

/**
 * The version Font Awesome's stylesheet is enqueued with (its cache buster) is the one the vendored files are: the
 * release named in assets/vendor/fontawesome/VERSION, whose pinned hashes the build checks, and in the stylesheet's
 * own banner.
 */
final class FontAwesomeVersionTest extends TestCase
{
    public function testTheEnqueuedVersionIsTheVendoredOne(): void
    {
        $dir = dirname(__DIR__, 3) . '/assets/vendor/fontawesome';
        $v   = preg_quote(Assets::FONT_AWESOME_VERSION, '/');

        self::assertMatchesRegularExpression('/\A@fortawesome\/fontawesome-free ' . $v . '\R/', (string) file_get_contents($dir . '/VERSION'));
        self::assertMatchesRegularExpression('/\A\/\*!\R \* Font Awesome Free ' . $v . ' by/', (string) file_get_contents($dir . '/css/all.min.css'));
    }
}
