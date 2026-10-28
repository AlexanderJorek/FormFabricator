<?php

namespace FabricatorForms\Tests\Integration\PDF;

use FabricatorForms\Admin\VerificationPage;
use FabricatorForms\Tests\Integration\TestCase;
use FabricatorForms\Tests\Support\Reflect;

/**
 * An image the verifier scales down (over DISPLAY_MAX_EDGE) goes into a data URI only: any byte GD printed would
 * precede the AJAX answer's JSON and break it.
 */
final class VerifierImageTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formats(): iterable
    {
        yield 'PNG' => ['image/png', 'imagepng'];
        yield 'JPEG' => ['image/jpeg', 'imagejpeg'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('formats')]
    public function testAScaledImageBecomesADataUriAndPrintsNothing(string $mime, string $encode): void
    {
        $image = imagecreatetruecolor(2400, 120);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 160));
        $file = (string) tempnam(get_temp_dir(), 'vimg');
        $encode($image, $file);
        unset($image);

        $level = ob_get_level();
        ob_start();
        try {
            $uri = (string) Reflect::call(VerificationPage::class, 'displayDataUri', $file, $mime);
        } finally {
            $printed = (string) ob_get_clean();
            unlink($file);
        }

        self::assertSame('', $printed, 'nothing of the image reaches the output');
        self::assertSame($level, ob_get_level(), 'no buffer left open');
        self::assertStringStartsWith('data:' . $mime . ';base64,', $uri);
        $decoded = (string) base64_decode(substr($uri, strlen('data:' . $mime . ';base64,')), true);
        $size    = getimagesizefromstring($decoded);
        self::assertIsArray($size);
        self::assertSame(1400, $size[0], 'scaled to the display edge');
    }
}
