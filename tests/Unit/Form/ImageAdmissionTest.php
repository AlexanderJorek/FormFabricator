<?php

namespace FabricatorForms\Tests\Unit\Form;

use Brain\Monkey\Functions;
use FabricatorForms\Form\FormProcessor;
use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\Overrides;
use FabricatorForms\Tests\Support\Reflect;
use FabricatorForms\Tests\Support\TestCase;
use FabricatorForms\Utils\MemoryBudget;

/**
 * Image admission on forms that build a PDF: the pixel limit, the memory reservation per image, and the messages a
 * visitor gets — the numbers TESTING.md §3 lists for a 512 MB budget ("44.7 megapixels", "at most 52.5 MB").
 *
 * The closing property test checks, over many hosts and budgets, that an accepted image fits and decodes, and that
 * a maximum shown really fits.
 */
final class ImageAdmissionTest extends TestCase
{
    private const MB   = 1048576;
    private const HARD = 200_000_000;

    private string $memoryLimit = '';
    private bool $changeable    = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memoryLimit = (string) ini_get('memory_limit');
        Functions\stubTranslationFunctions();
        Functions\when('number_format_i18n')->alias(static fn($n, $d = 0) => number_format((float) $n, (int) $d));
        Functions\when('sanitize_file_name')->alias(static fn($n) => preg_replace('/[^A-Za-z0-9._-]/', '', (string) $n));
        Functions\when('wp_convert_hr_to_bytes')->alias(static function ($v): int {
            $v = strtolower(trim((string) $v));
            $b = (int) $v;
            return match (true) {
                str_contains($v, 'g') => $b * 1024 ** 3,
                str_contains($v, 'm') => $b * 1024 ** 2,
                str_contains($v, 'k') => $b * 1024,
                default               => $b,
            };
        });
        Functions\when('wp_is_ini_value_changeable')->alias(fn(): bool => $this->changeable);
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memoryLimit);
        (new \ReflectionProperty(MemoryBudget::class, 'budget_memo'))->setValue(null, null);
        parent::tearDown();
    }

    public function testSafePixelsKeepsTheFormerFormulaAndMemoryLimitForIsItsInverse(): void
    {
        $old = static fn(int $l): int => $l <= 0 ? self::HARD : max(1_000_000, min((int) ($l * 0.5 / 6), self::HARD));
        mt_srand(20260930);
        for ($i = 0; $i < 20000; $i++) {
            $l = $i < 3 ? [-1, 0, 1][$i] : mt_rand(1, 8 * 1024 * self::MB);
            self::assertSame($old($l), PdfUtils::safePixelsFor($l), "safePixelsFor($l)");
            $p = mt_rand(0, self::HARD);
            self::assertGreaterThanOrEqual($p, PdfUtils::safePixelsFor(PdfUtils::memoryLimitFor($p)), "memoryLimitFor($p)");
        }
    }

    public function testTheNumbersTestingMdListsForA512MbBudget(): void
    {
        ini_set('memory_limit', '256M');
        $this->setBudgetMb(512);
        $limit = self::pixelLimit();
        self::assertSame(44_739_242, $limit);

        self::assertSame(
            ['f' => '"IMG_1.jpg" has 50.0 megapixels (12.4 MB), but images can have at most 44.7 megapixels. Please upload a smaller image.'],
            self::errors('IMG_1.jpg', 13_000_000, 49_939_200, $limit),
            '50 MP photo'
        );
        self::assertSame(
            ['f' => 'All files together are 103.0 MB, but with "scan.png" they can be at most 52.5 MB. Please upload smaller files.'],
            self::errors('scan.png', 103 * self::MB, 36_000_000, $limit),
            '103 MB scan'
        );
        foreach ([10, 30, 50, 52.5] as $mb) {
            self::assertSame([], self::errors('scan.png', (int) ($mb * self::MB), 36_000_000, $limit), "36 MP scan at $mb MB");
        }
        self::assertSame([], self::errors('IMG_2.jpg', 4 * self::MB, 12_192_768, $limit), '12 MP phone photo');
        self::assertArrayHasKey('f', self::errors('fake.png', 4096, 199_999_999, $limit), 'a few-KB PNG claiming 200 MP');
    }

    public function testTheLimitIsExactAndRoundedHonestly(): void
    {
        ini_set('memory_limit', '256M');
        $this->setBudgetMb(512);
        $limit = self::pixelLimit();

        $edge = Reflect::call(FormProcessor::class, 'imageSizeErrors', self::one('e.jpg', 1, $limit + 1), $limit, 0, 1)['f'];
        self::assertStringContainsString('has 44.8 megapixels (0.1 MB)', $edge);
        self::assertStringContainsString('at most 44.7 megapixels', $edge);
        self::assertSame([], self::errors('ok.jpg', 1, $limit, $limit), 'exactly at the limit');
    }

    public function testAHostWithAFixed128MbLimit(): void
    {
        $this->changeable = false;
        ini_set('memory_limit', '128M');
        $this->setBudgetMb(512);
        self::assertSame(11_184_810, self::pixelLimit(), 'what 128 MB can decode, below the budget\'s own limit');
    }

    public function testAcceptedImagesFitAndShownMaximumsAreTrue(): void
    {
        mt_srand(20261001);
        $hosts = ['128M' => 128 * self::MB, '256M' => 256 * self::MB, '512M' => 512 * self::MB, '2G' => 2048 * self::MB, '-1' => -1];
        foreach ($hosts as $ini => $current) {
            ini_set('memory_limit', (string) $ini);
            foreach ([true, false] as $changeable) {
                $this->changeable = $changeable;
                foreach ([512, 1024, 8192] as $budget_mb) {
                    $this->setBudgetMb($budget_mb);
                    $budget = $budget_mb * self::MB;
                    $limit  = self::pixelLimit();
                    $own    = $changeable ? self::HARD : PdfUtils::safePixelsFor($current);
                    self::assertSame(min($own, intdiv($budget, 12), intdiv($budget - MemoryBudget::estimateBytes(self::MB), 6)), $limit, "limit: $ini $budget_mb");

                    for ($i = 0; $i < 200; $i++) {
                        $pixels = mt_rand(0, 2) === 0 ? mt_rand(1, 5_000_000) : mt_rand(1, 300_000_000);
                        $bytes  = mt_rand(1, 60 * self::MB);
                        $total  = $bytes + mt_rand(0, 60 * self::MB);
                        $base   = MemoryBudget::estimateBytes($total);
                        if ($base > $budget) {
                            continue; // refused as too large in total before images are looked at
                        }
                        $errs = Reflect::call(FormProcessor::class, 'imageSizeErrors', self::one('x.jpg', $bytes, $pixels), $limit, $base, $total);
                        $ctx  = "host $ini, changeable " . (int) $changeable . ", budget $budget_mb, pixels $pixels, total $total";
                        if ($errs === []) {
                            $estimate = self::withImage($base, $pixels);
                            self::assertLessThanOrEqual($budget, $estimate, "accepted but over budget: $ctx");
                            self::assertGreaterThanOrEqual($pixels, PdfUtils::safePixelsFor(self::raised($current, $estimate, $changeable)), "accepted but not decodable: $ctx");
                        } elseif (str_starts_with($errs['f'], 'All files together')) {
                            self::assertLessThanOrEqual($limit, $pixels, "files message for an image above the pixel limit: $ctx");
                            $room = (int) (floor(MemoryBudget::largestUploadBytes($pixels * 6) / 104857.6) * 104857.6);
                            self::assertGreaterThanOrEqual(self::MB, $room, "shown maximum below 1 MB: $ctx");
                            self::assertLessThan($total, $room, "shown maximum not below the current total: $ctx");
                            self::assertLessThanOrEqual($budget, self::withImage(MemoryBudget::estimateBytes($room), $pixels), "shown maximum does not fit: $ctx");
                        } else {
                            self::assertGreaterThan($limit, $pixels, "megapixel message within the limit: $ctx");
                        }
                    }
                }
            }
        }
    }

    public function testUploadedImagesArePairedWithTheirFieldsAndTiffGoesToTheDocuments(): void
    {
        $dims = [
            '/t/a' => [4000, 3000, 'mime' => 'image/jpeg'],
            '/t/b' => [100, 50, 'mime' => 'image/png'],
            '/t/d' => [9000, 9000, 'mime' => 'image/tiff'],
            '/t/e' => [9000, 9000, 'mime' => 'image/gif'],
        ];
        $this->setBudgetMb(512);
        Overrides::$uploadedFiles = ['/t/a', '/t/b', '/t/c', '/t/d', '/t/e'];
        Functions\when('wp_getimagesize')->alias(static fn(string $f) => $dims[$f] ?? false);

        $found = Reflect::call(FormProcessor::class, 'uploadedImages', [
            'photos' => ['name' => ['a.jpg', 'b.png', 'c.pdf'], 'tmp_name' => ['/t/a', '/t/b', '/t/c'], 'size' => [11, 22, 33]],
            'scan'   => ['name' => 'd.tif', 'tmp_name' => '/t/d', 'size' => 44],
            'big'    => ['name' => 'e.gif', 'tmp_name' => '/t/e', 'size' => 55],
        ]);

        self::assertSame(['a.jpg', 'b.png', 'e.gif'], array_column($found, 'name'));
        self::assertSame(['photos', 'photos', 'big'], array_column($found, 'field'));
        self::assertSame([11, 22, 55], array_column($found, 'bytes'));
        self::assertSame([12_000_000, 5000, 81_000_000], array_column($found, 'pixels'));

        $errs = Reflect::call(
            FormProcessor::class,
            'imageSizeErrors',
            array_merge($found, [['field' => 'photos', 'name' => 'x.jpg', 'bytes' => 1, 'pixels' => 81_000_000]]),
            10_000_000,
            0,
            1
        );
        self::assertSame(['photos', 'big'], array_keys($errs), 'one message per field');
        self::assertStringStartsWith('"a.jpg" has 12.0 megapixels', $errs['photos'], 'the first offending image is named');
    }

    public function testSubmittedTextReservesMemoryAndASignatureCountsAsImageData(): void
    {
        // The reservation counted uploads only, while mPDF lays text out at about 500 bytes of memory per byte (measured:
        // 109 KB of text peaked 62.6 MB): a form of long answers started renders no limit covered.
        $raw = [
            'note' => str_repeat('x', 100000),
            'sig'  => 'data:image/png;base64,' . str_repeat('A', 1000),
            'file' => ['name' => 'a.pdf', 'tmp_name' => '/tmp/php123', 'type' => 'application/pdf', 'error' => 0, 'size' => 5],
            'addr' => ['street' => 'Main St 1', 'city' => 'Köln'],
        ];
        [$text, $image] = Reflect::call(FormProcessor::class, 'answerPayloadBytes', $raw);
        self::assertSame(100000 + strlen('Main St 1') + strlen('Köln') + strlen('a.pdf'), $text);
        self::assertSame(strlen($raw['sig']), $image, 'a data URI is image data, not text laid out letter by letter');
        self::assertGreaterThanOrEqual(MemoryBudget::estimateBytes(0) + 48 * self::MB, MemoryBudget::estimateBytes(0, 100000));
    }

    public function testMemoryExhaustionIsRecognised(): void
    {
        $isOom = static fn(?array $e): bool => Reflect::call(FormProcessor::class, 'isMemoryExhaustion', $e);
        self::assertTrue($isOom(['type' => E_ERROR, 'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)']));
        self::assertTrue($isOom(['type' => E_ERROR, 'message' => 'Out of memory (allocated 2097152) (tried to allocate 4096 bytes)']));
        self::assertFalse($isOom(['type' => E_ERROR, 'message' => 'Call to undefined function foo()']));
        self::assertFalse($isOom(null));
    }

    private function setBudgetMb(int $mb): void
    {
        (new \ReflectionProperty(MemoryBudget::class, 'budget_memo'))->setValue(null, $mb * self::MB);
    }

    private static function pixelLimit(): int
    {
        return PdfUtils::imagePixelLimit();
    }

    private static function withImage(int $base, int $pixels): int
    {
        return Reflect::call(FormProcessor::class, 'estimateWithImage', $base, $pixels);
    }

    /**
     * @return array<string, string>
     */
    private static function errors(string $name, int $bytes, int $pixels, int $limit): array
    {
        return Reflect::call(FormProcessor::class, 'imageSizeErrors', self::one($name, $bytes, $pixels), $limit, MemoryBudget::estimateBytes($bytes), $bytes);
    }

    /**
     * @return array<int, array{field: string, name: string, bytes: int, pixels: int}>
     */
    private static function one(string $name, int $bytes, int $pixels): array
    {
        return [['field' => 'f', 'name' => $name, 'bytes' => $bytes, 'pixels' => $pixels]];
    }

    /**
     * The memory_limit wp_raise_memory_limit() leaves when asked for $target.
     */
    private static function raised(int $current, int $target, bool $changeable): int
    {
        if (!$changeable || $current === -1 || $current >= $target) {
            return $current;
        }
        return $target > 256 * self::MB ? $target : max(256 * self::MB, $current);
    }
}
