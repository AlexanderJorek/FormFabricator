<?php

namespace FabricatorForms\Tests\Integration\PDF;

use FabricatorForms\Admin\PDFLayoutEditor;
use FabricatorForms\Fields\FieldRegistry;
use FabricatorForms\Form\FormModel;
use FabricatorForms\PDF\Generator;
use FabricatorForms\PDF\HashSeal;
use FabricatorForms\Tests\Integration\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * FormFabricator → PDF Layout settings in a generated PDF (TESTING.md §6, §10): fonts, colours, margins, a Media Library
 * logo, the footer on every page, and hiding the footer. Read from the PDF itself — its fonts, its drawing operators and
 * its text per page. Whether the result looks right stays a person's call.
 */
#[Group('package')]
final class PdfLayoutTest extends TestCase
{
    /** mm to PDF points. */
    private const PT_PER_MM = 72 / 25.4;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        if (HashSeal::activeKeyProblem() !== '') {
            HashSeal::createInitialKey();
        }
    }

    public function testColoursMarginsAndBodySizeReachThePdf(): void
    {
        update_option('fabricator_forms_pdf_layout', [
            'accent_color'    => '#112233',
            'separator_color' => '#445566',
            'margin_left'     => 40,
            'margin_right'    => 25,
            'font_size_body'  => 12,
        ]);

        $content = self::pageContent($this->generate());

        self::assertStringContainsString('0.067 0.133 0.200 RG', $content, 'the accent colour');
        self::assertStringContainsString('0.267 0.333 0.400 RG', $content, 'the separator colour');
        self::assertStringContainsString(sprintf(' %.3f ', 40 * self::PT_PER_MM), $content, 'the left margin');
        self::assertStringContainsString(sprintf(' %.3f ', 595.28 - 25 * self::PT_PER_MM), $content, 'the right margin (A4 width minus it)');
        self::assertStringContainsString(' 12.000 Tf', $content, 'the body size');
    }

    public function testEveryFontTheEditorOffersIsEmbeddedAndKeptByTheBuild(): void
    {
        $offered = $this->offeredFonts();
        self::assertSame(['dejavusans', 'dejavuserif', 'dejavusansmono', 'freemono'], $offered, 'the fonts the editor offers');
        $fontdata = (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'];
        $kept     = self::fontsTheBuildKeeps();
        $expected = ['dejavusans' => 'DejaVuSans', 'dejavuserif' => 'DejaVuSerif', 'dejavusansmono' => 'DejaVuSansMono', 'freemono' => 'FreeMono'];

        foreach ($offered as $family) {
            update_option('fabricator_forms_pdf_layout', ['font_family' => $family]);
            preg_match_all('/\/BaseFont\s*\/[A-Z]{6}\+([A-Za-z-]+)/', $this->generate(), $m);
            self::assertContains($expected[$family], $m[1], "$family is the font of the PDF");

            foreach (array_intersect_key($fontdata[$family], array_flip(['R', 'B', 'I', 'BI'])) as $file) {
                self::assertContains($file, $kept, "$family needs $file, which the build's font trim (tools/build-config.php) must keep");
            }
        }
    }

    public function testTheFooterAndPageNumbersAreOnEveryPageOfALongSubmission(): void
    {
        update_option('fabricator_forms_pdf_layout', ['footer_text' => 'Acme GmbH, page {PAGENO} of {nbpg}']);

        $pages = self::pageTexts($this->generate(str_repeat("A long line of the visitor's message.\n", 300)));

        self::assertGreaterThanOrEqual(3, count($pages), 'the submission runs over several pages');
        foreach ($pages as $i => $text) {
            $n = $i + 1;
            self::assertStringContainsString('Acme GmbH, page ' . $n . ' of ' . count($pages), $text, "footer on page $n");
            self::assertStringContainsString('Page ' . $n . ' of ' . count($pages), $text, "page number on page $n");
        }
        self::assertStringContainsString('Order', $pages[0], 'the title heads the first page');
    }

    public function testHidingTheFooterRemovesItAndThePageNumbersButKeepsTheFields(): void
    {
        update_option('fabricator_forms_pdf_layout', ['footer_text' => 'Acme GmbH', 'section_hidden' => ['footer']]);

        $text = implode("\n", self::pageTexts($this->generate()));

        self::assertStringNotContainsString('Acme GmbH', $text);
        self::assertStringNotContainsString('Page 1 of', $text);
        self::assertStringContainsString('Ada Lovelace', $text);
    }

    public function testALogoFromTheMediaLibraryIsInTheHeader(): void
    {
        $im = imagecreatetruecolor(120, 40);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 10, 120, 200));
        $file = get_temp_dir() . 'logo-' . wp_generate_password(6, false) . '.png';
        imagepng($im, $file);
        $logo = self::factory()->attachment->create_upload_object($file);
        unlink($file);
        update_option('fabricator_forms_pdf_layout', ['header_layout' => ['rows' => 8, 'elements' => [
            ['id' => 'logo', 'type' => 'image', 'src' => wp_get_attachment_url($logo), 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6],
        ]]]);

        $pdf = $this->generate();

        self::assertSame(1, preg_match_all('/\/Subtype\s*\/Image\b[^>]*\/Width 120\b/', $pdf), 'the logo, at its own size');
    }

    public function testAHeaderImageTooLargeToDecodeIsLeftOutAndThePdfStillMade(): void
    {
        // Stored before its size was checked, or replaced since: a PDF without it beats no PDF at all.
        $huge = \FabricatorForms\Tests\Integration\Admin\PdfLayoutSaveTest::pngAttachment(30000, 30000);
        update_option('fabricator_forms_pdf_layout', ['header_layout' => ['rows' => 8, 'elements' => [
            ['id' => 'logo', 'type' => 'image', 'src' => wp_get_attachment_url($huge), 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6],
        ]]]);

        $pdf = $this->generate();

        self::assertSame(0, preg_match('/\/Subtype\s*\/Image\b/', $pdf), 'no image');
        self::assertStringContainsString('Ada Lovelace', implode("\n", self::pageTexts($pdf)));
    }

    public function testTheLayoutsImagesCountInASubmissionsMemoryEstimate(): void
    {
        $logo   = \FabricatorForms\Tests\Integration\Admin\PdfLayoutSaveTest::pngAttachment(2000, 1500);
        $header = \FabricatorForms\Tests\Integration\Admin\PdfLayoutSaveTest::pngAttachment(3000, 2000);
        update_option('fabricator_forms_pdf_layout', ['logo_url' => wp_get_attachment_url($logo), 'header_layout' => ['rows' => 8, 'elements' => [
            ['id' => 'e1', 'type' => 'image', 'src' => wp_get_attachment_url($header), 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6],
            ['id' => 'e2', 'type' => 'image', 'src' => 'https://elsewhere.example/never-read.png', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6],
        ]]]);

        self::assertSame(6000000, \FabricatorForms\Tests\Support\Reflect::call(\FabricatorForms\Form\FormProcessor::class, 'largestLayoutImage'));
    }

    public function testASubmissionsPdfFolderGoesWhenTheRequestEndsButTheSharedFontCacheStays(): void
    {
        // The folder is mPDF's temp dir for the submission, and holds the first pass with every answer: its removal is
        // registered before anything is written, so it goes at the end of the request even when the request is cut
        // short. mPDF's font metrics reach it as links to the shared cache, which removing them must leave alone.
        \FabricatorForms\Tests\Support\Overrides::$shutdownFunctions = [];
        // The first fills the shared cache if it is empty; the second gets it linked into its folder.
        $this->generate();
        $this->generate();
        $folders = [];
        foreach (\FabricatorForms\Tests\Support\Overrides::$shutdownFunctions as [$callback, $args]) {
            if ($callback === [\FabricatorForms\Utils\PrivateDir::class, 'removeTree'] && str_contains((string) $args[0], '/pdf/')) {
                $folders[] = (string) $args[0];
            }
        }
        self::assertNotEmpty($folders, 'the removal of the PDF folder is registered');
        $folder = $folders[0];
        $shared = glob(\FabricatorForms\Utils\PrivateDir::sharedMpdfTemp() . '/mpdf/ttfontdata/*') ?: [];
        self::assertNotEmpty($shared, 'the font metrics this PDF needed are in the shared cache');
        self::assertFileExists($folder . '/mpdf/ttfontdata/' . basename($shared[0]), 'and reach the next PDF in its own folder');
        // What a request stopped before the inline delete leaves behind.
        file_put_contents($folder . '/SL_cut-short.pdf', '%PDF-1.4');

        \FabricatorForms\Tests\Support\Overrides::runShutdownFunctions();

        self::assertDirectoryDoesNotExist($folder);
        clearstatcache();
        foreach ($shared as $file) {
            self::assertFileExists($file, 'shared, so never removed with a submission');
        }
    }

    /**
     * A sealed PDF of one submission, as a notification would attach it.
     */
    private function generate(string $message = 'Please deliver on Monday.'): string
    {
        $fields = [
            ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['id' => 'note', 'type' => 'textarea', 'label' => 'Message'],
        ];
        $form = FormModel::save(['title' => 'Order', 'fields' => $fields, 'notifications' => [], 'settings' => []], 0, true);
        self::assertIsInt($form);
        $path = Generator::generate(FieldRegistry::mapSubmission($fields, ['name' => 'Ada Lovelace', 'note' => $message], [], []), $form, 'Order');
        self::assertIsString($path, 'Generator produced a PDF');
        $bytes = (string) file_get_contents($path);
        wp_delete_file($path);
        return $bytes;
    }

    /**
     * Every Flate stream of $pdf that draws text or lines, unpacked.
     */
    private static function pageContent(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $m);
        $out = '';
        foreach ($m[1] as $data) {
            $decoded = @gzuncompress($data);
            if ($decoded !== false && (str_contains($decoded, ' Tf') || str_contains($decoded, ' RG'))) {
                $out .= $decoded . "\n";
            }
        }
        return $out;
    }

    /**
     * @return string[] Each page's text, ligatures spelled out.
     */
    private static function pageTexts(string $pdf): array
    {
        $pages = (new \Smalot\PdfParser\Parser())->parseContent($pdf)->getPages();
        return array_map(
            static fn($page): string => str_replace(["\u{FB00}", "\u{FB01}", "\u{FB02}"], ['ff', 'fi', 'fl'], $page->getText()),
            $pages
        );
    }

    /**
     * The font families the PDF Layout editor's font menu offers.
     *
     * @return string[]
     */
    private function offeredFonts(): array
    {
        ob_start();
        PDFLayoutEditor::render();
        $page = (string) ob_get_clean();
        self::assertSame(1, preg_match('/<select[^>]*name="font_family"[^>]*>(.*?)<\/select>/s', $page, $select));
        preg_match_all('/<option value="([^"]+)"/', $select[1], $options);
        return $options[1];
    }

    /**
     * The font files the release build's mPDF font trim keeps (tools/build-config.php, "keepFonts").
     *
     * @return string[]
     */
    private static function fontsTheBuildKeeps(): array
    {
        $config = require dirname(__DIR__, 3) . '/tools/build-config.php';
        return array_values(array_filter($config['keepFonts'], static fn(string $file): bool => str_ends_with($file, '.ttf')));
    }
}
