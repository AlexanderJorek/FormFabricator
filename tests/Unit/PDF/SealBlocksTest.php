<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\Admin\Verificationpage;
use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\PdfFixtures;
use FabricatorForms\Tests\Support\Reflect;
use FabricatorForms\Tests\Support\TestCase;

/**
 * Finding the seal: PdfUtils::sealBlocks()/withoutSealBlocks() against the lazy regexes they replaced, and the
 * verifier's raw byte check (rawPdfHasSeal()) that looks for the seal marker in UTF-16 or plain stream text.
 */
final class SealBlocksTest extends TestCase
{
    private const PARTS = ['---BEGIN-SEAL---', '---END-SEAL---', '---', 'BEGIN-SEAL', 'END-SEAL---', '-', 'x', "\n", 'SEAL', '--'];

    private string $dir = '';

    protected function tearDown(): void
    {
        PdfFixtures::removeTree($this->dir);
        parent::tearDown();
    }

    public function testSealBlocksMatchesTheLazyRegexOnRandomText(): void
    {
        mt_srand(20260926);
        for ($n = 0; $n < 60000; $n++) {
            $t = '';
            for ($i = mt_rand(0, 14); $i > 0; $i--) {
                $t .= self::PARTS[mt_rand(0, count(self::PARTS) - 1)];
            }
            preg_match_all('/---BEGIN-SEAL---(.*?)---END-SEAL---/s', $t, $m, PREG_OFFSET_CAPTURE);
            $expect = [];
            foreach ($m[0] as $k => $whole) {
                $expect[] = [$whole[1], strlen($whole[0]), $m[1][$k][0]];
            }
            self::assertSame($expect, PdfUtils::sealBlocks($t), json_encode($t));
            self::assertSame(preg_replace('/---BEGIN-SEAL---.*?---END-SEAL---/s', '', $t), PdfUtils::withoutSealBlocks($t), json_encode($t));
        }
    }

    public function testMarkerInUtf16AtAnEvenOffsetIsFound(): void
    {
        self::assertTrue($this->hasSeal(self::utf16('some text before ---BEGIN-SEAL--- and after')));
    }

    public function testMarkerInUtf16AtAnOddOffsetIsFound(): void
    {
        self::assertTrue($this->hasSeal('X' . self::utf16('padding text so the stream is long ---BEGIN-SEAL--- tail')));
    }

    public function testMarkerInPlainTextIsFound(): void
    {
        self::assertTrue($this->hasSeal('BT (plain ascii content stream ---BEGIN-SEAL--- here) Tj ET'));
    }

    public function testMarkerBrokenByNonZeroHighBytesIsNotFound(): void
    {
        // Every character's high byte is 0x01: not the pairs the check reads.
        $broken = '';
        foreach (str_split('filler filler ---BEGIN-SEAL--- filler') as $ch) {
            $broken .= "\x01" . $ch;
        }
        self::assertFalse($this->hasSeal($broken));
    }

    public function testAStreamThatIsNotFlateDecodeIsNotRead(): void
    {
        self::assertFalse($this->hasSeal('long enough text with ---BEGIN-SEAL--- in it', '/Length 1'));
    }

    public function testADecodedStreamShorterThan32BytesIsSkipped(): void
    {
        self::assertFalse($this->hasSeal('---BEGIN-SEAL---'));
    }

    public function testAnUnreadableFileHasNoSeal(): void
    {
        self::assertFalse(Reflect::call(Verificationpage::class, 'rawPdfHasSeal', sys_get_temp_dir() . '/fabricator-missing-' . bin2hex(random_bytes(4)) . '.pdf'));
    }

    private static function utf16(string $ascii): string
    {
        return (string) mb_convert_encoding($ascii, 'UTF-16BE', 'ASCII');
    }

    /**
     * Writes a one-stream PDF whose stream decodes to $decoded and asks the verifier whether it carries a seal.
     */
    private function hasSeal(string $decoded, string $dict = '/Filter /FlateDecode'): bool
    {
        $data = $dict === '/Filter /FlateDecode' ? gzcompress($decoded) : $decoded;
        $pdf  = PdfFixtures::build([1 => PdfFixtures::stream($dict, $data)]);
        if ($this->dir === '') {
            $this->dir = PdfFixtures::tempDir('fabricator-seal-');
        }
        $path = $this->dir . '/doc-' . bin2hex(random_bytes(3)) . '.pdf';
        file_put_contents($path, $pdf);
        return Reflect::call(Verificationpage::class, 'rawPdfHasSeal', $path);
    }
}
