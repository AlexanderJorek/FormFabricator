<?php

namespace FabricatorForms\Tests\Integration\PDF;

use FabricatorForms\Admin\Verificationpage;
use FabricatorForms\Fields\FieldRegistry;
use FabricatorForms\Form\FormModel;
use FabricatorForms\PDF\Generator;
use FabricatorForms\PDF\HashSeal;
use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Integration\TestCase;
use FabricatorForms\Tests\Support\Reflect;
use PHPUnit\Framework\Attributes\Group;

/**
 * A real sealed PDF from a real submission, through the real verifier: authentic as generated, and caught when its
 * bytes are edited in place or an incremental update is appended (a "shadow attack"). TESTING.md §5.
 *
 * The verdict is read from the class the verifier puts on its summary line (fabricator-pdf-verdict-pass/-fail/-warn).
 */
#[Group('package')]
final class SealRoundTripTest extends TestCase
{
    private const FIELDS = [
        ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
        ['id' => 'amount', 'type' => 'currency', 'label' => 'Amount', 'currency' => 'EUR'],
        ['id' => 'note', 'type' => 'textarea', 'label' => 'Note'],
        ['id' => 'sig', 'type' => 'signature', 'label' => 'Signature'],
    ];

    private const LINKS_HTML = '<p>Questions: <a href="mailto:info@club.example">info@club.example</a>, terms: '
        . '<a href="https://club.example/terms">our terms</a></p>';

    private string $dir = '';

    public function set_up(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP_UnitTestCase fixture method.
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        if (HashSeal::activeKeyProblem() !== '') {
            HashSeal::createInitialKey();
        }
        $this->dir = get_temp_dir() . 'fabricator-seal-' . wp_generate_password(6, false);
        wp_mkdir_p($this->dir);
    }

    public function tear_down(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP_UnitTestCase fixture method.
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
        parent::tear_down();
    }

    public function testAFreshlyGeneratedPdfVerifiesAsAuthentic(): void
    {
        $pdf  = $this->sealedPdf();
        $html = $this->verify($pdf, 'fresh.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
        self::assertDoesNotMatchRegularExpression('#/BaseFont\s*/(?:[A-Z]{6}\+)?FreeSerif#', $pdf, 'no fallback font where none is needed');
        self::assertStringContainsString('HMAC valid: the seal holds exactly what was signed', $html, 'the seal section opens with the HMAC verdict');

        // The signature's card: green, MATCH, its hash shown once.
        $cards = self::imageCards($html);
        self::assertNotEmpty($cards, 'the signature has an image card');
        foreach ($cards as [$state, $hash, $card]) {
            self::assertSame('pass', $state);
            self::assertStringContainsString('MATCH', $card);
            self::assertSame(1, substr_count($card, $hash), 'the hash appears once in its card');
        }
    }

    public function testAPdfWithoutAnyImageVerifiesAsAuthentic(): void
    {
        // The background grid is a Form XObject in every PDF: a genuine PDF without any image must still verify.
        $html = $this->verify($this->sealedPdf(false), 'no-image.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
    }

    public function testAnEditedPageIsCaught(): void
    {
        // TESTING.md §5: tamper "at the byte level", as a PDF editor would — the page's content stream decompressed,
        // changed, recompressed, and the file's /Length and xref table repaired so every reader still opens it.
        $tampered = self::editPageContent($this->sealedPdf(), static fn(string $page): string => $page . "\nBT /F1 12 Tf 72 72 Td (PAID IN FULL) Tj ET\n");

        $html = $this->verify($tampered, 'edited.pdf');
        self::assertSame('fail', self::verdict($html));
        self::assertStringContainsString('Page content was modified or added', $html);
    }

    /**
     * A changed field shows only the changed words, each with a few words around it, however long the text: a reader
     * finds the change without comparing two long texts letter by letter.
     */
    public function testAChangedFieldShowsTheChangedWordsInContext(): void
    {
        $diff  = static fn(string $sealed, string $pdf): string => Reflect::call(Verificationpage::class, 'differenceHtml', $sealed, $pdf);
        $words = array_map(static fn(int $i): string => 'word' . $i, range(1, 300));
        $text  = implode(' ', $words);

        $html = $diff($text, str_replace('word150 ', 'Alexander ', $text));
        self::assertSame(1, substr_count($html, 'fabricator-pdf-diff__hunk'));
        self::assertStringContainsString('… word144 word145 word146 word147 word148 word149 <del>word150</del> word151 word152 word153 word154 word155 word156 …', $html);
        self::assertStringContainsString('<ins>Alexander</ins>', $html);
        self::assertLessThan(1000, strlen($html), 'the excerpt, not the text');

        // Words added or removed: the other side shows that nothing stood there.
        self::assertStringContainsString("<ins class='fabricator-pdf-diff__none'>(nothing)</ins>", $diff($text, str_replace('word150 ', '', $text)));

        // Five stretches at most, then a note that more follow.
        $many = str_replace(['word20 ', 'word60 ', 'word100 ', 'word140 ', 'word180 ', 'word220 ', 'word260 '], 'X ', $text);
        $html = $diff($text, $many);
        self::assertSame(5, substr_count($html, 'fabricator-pdf-diff__hunk'));
        self::assertStringContainsString('…and further changes after these.', $html);

        // A difference no word shows (spacing only) is said so, and the PDF's text is never markup.
        self::assertStringContainsString('the difference lies in characters that do not show as words', $diff('a b', 'a  b'));
        self::assertStringContainsString('&lt;b&gt;', $diff('a b c', 'a <b> c'));
    }

    public function testAChangedBackgroundFormXObjectIsCaught(): void
    {
        // A PDF without images is accepted because the content-stream check holds every Form XObject to the seal.
        // Redrawing the background grid (a Form XObject) must still fail.
        $tampered = self::editStream(
            $this->sealedPdf(false),
            static fn(string $dict): bool => (bool) preg_match('#/Subtype\s*/Form\b#', $dict),
            static fn(string $grid): string => $grid . "\nq 1 0 0 rg 0 0 200 50 re f Q\n"
        );

        $html = $this->verify($tampered, 'grid-edited.pdf');
        self::assertSame('fail', self::verdict($html));
        self::assertStringContainsString('UNRECOGNISED', $html);
    }

    public function testAnAddedFormXObjectIsCaught(): void
    {
        // A second Form XObject, written into the file in place (not as an incremental update), must fail as well.
        $pdf = $this->sealedPdf(false);
        preg_match('/xref\n0 (\d+)\n/', $pdf, $header, 0, (int) strrpos($pdf, "\nxref\n"));
        $num    = (int) $header[1];
        $data   = (string) gzcompress("q 1 0 0 rg 0 0 200 50 re f Q\n");
        $object = $num . " 0 obj\n<< /Type /XObject /Subtype /Form /BBox [0 0 200 50] /Filter /FlateDecode /Length "
            . strlen($data) . " >>\nstream\n" . $data . "\nendstream\nendobj\n";
        $xrefAt = (int) strrpos($pdf, "\nxref\n") + 1;
        $pdf    = substr($pdf, 0, $xrefAt) . $object . substr($pdf, $xrefAt);
        $pdf    = str_replace("xref\n0 $num\n", "xref\n0 " . ($num + 1) . "\n", $pdf);
        $pdf    = (string) preg_replace('/\/Size ' . $num . '\b/', '/Size ' . ($num + 1), $pdf);

        $html = $this->verify(self::rebuildXref($pdf), 'form-added.pdf');
        self::assertSame('fail', self::verdict($html));
        self::assertStringContainsString('UNRECOGNISED', $html);
    }

    public function testASoftMaskDeclaringAHugeSizeEndsInAVerdictNotAMemoryFatal(): void
    {
        // The mask's /Width sized str_repeat() uncapped: 4e9 ended the check in "Allowed memory size exhausted".
        $pdf = $this->sealedPdf();
        preg_match('/xref\n0 (\d+)\n/', $pdf, $header, 0, (int) strrpos($pdf, "\nxref\n"));
        $num = (int) $header[1];
        // Point the signature image at a new mask object, written into the file in place.
        $pdf = (string) preg_replace('#(/Subtype\s*/Image)#', '$1 /SMask ' . $num . ' 0 R', $pdf, 1, $hits);
        self::assertSame(1, $hits, 'the PDF holds an image');
        $mask   = (string) gzcompress(str_repeat("\x02\xff", 64));
        $object = $num . " 0 obj\n<< /Type /XObject /Subtype /Image /Width 4000000000 /Height 1 /ColorSpace /DeviceGray"
            . " /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Columns 4000000000 >> /Length "
            . strlen($mask) . " >>\nstream\n" . $mask . "\nendstream\nendobj\n";
        $xrefAt = (int) strrpos($pdf, "\nxref\n") + 1;
        $pdf    = substr($pdf, 0, $xrefAt) . $object . substr($pdf, $xrefAt);
        $pdf    = str_replace("xref\n0 $num\n", "xref\n0 " . ($num + 1) . "\n", $pdf);
        $pdf    = (string) preg_replace('/\/Size ' . $num . '\b/', '/Size ' . ($num + 1), $pdf);

        $html = $this->verify(self::rebuildXref($pdf), 'huge-mask.pdf');
        self::assertContains(self::verdict($html), ['pass', 'fail', 'warn'], 'the check ran to a verdict');
    }

    public function testASoftMaskSmallerThanItsImageShapesThePreviewAndIsNoMismatch(): void
    {
        // TESTING.md §5. A mask smaller than its image: a 2×2 mask, transparent top left and bottom right, must shape the
        // preview's corners and leave the image's check alone.
        $pdf = $this->sealedPdf();
        preg_match('/xref\n0 (\d+)\n/', $pdf, $header, 0, (int) strrpos($pdf, "\nxref\n"));
        $num = (int) $header[1];
        $pdf = (string) preg_replace('#(/Subtype\s*/Image)(?![^>]*/SMask)#', '$1 /SMask ' . $num . ' 0 R', $pdf, 1, $hits);
        self::assertSame(1, $hits, 'the PDF holds an image without a mask');
        $mask   = (string) gzcompress("\x00\xff\xff\x00");
        $object = $num . " 0 obj\n<< /Type /XObject /Subtype /Image /Width 2 /Height 2 /ColorSpace /DeviceGray"
            . " /BitsPerComponent 8 /Filter /FlateDecode /Length " . strlen($mask) . " >>\nstream\n" . $mask . "\nendstream\nendobj\n";
        $xrefAt = (int) strrpos($pdf, "\nxref\n") + 1;
        $pdf    = substr($pdf, 0, $xrefAt) . $object . substr($pdf, $xrefAt);
        $pdf    = str_replace("xref\n0 $num\n", "xref\n0 " . ($num + 1) . "\n", $pdf);
        $pdf    = (string) preg_replace('/\/Size ' . $num . '\b/', '/Size ' . ($num + 1), $pdf);

        $html  = $this->verify(self::rebuildXref($pdf), 'small-mask.pdf');
        $cards = self::imageCards($html);
        self::assertNotSame([], $cards);
        self::assertSame(['pass'], array_values(array_unique(array_column($cards, 0))), 'no image is a mismatch');
        self::assertStringNotContainsString('MISMATCH', $html);

        $previews = array_filter(array_map(
            static fn(array $card): string => preg_match('#src=["\']data:image/png;base64,([^"\']+)#', $card[2], $m) ? (string) base64_decode($m[1]) : '',
            $cards
        ));
        $masked = null;
        foreach ($previews as $png) {
            $im = imagecreatefromstring($png);
            if ($im !== false && (imagecolorat($im, 0, 0) >> 24) > 0) {
                $masked = $im;
            }
        }
        self::assertNotNull($masked, 'a preview carries the mask\'s transparency');
        $alpha = static fn(int $x, int $y): int => (imagecolorat($masked, $x, $y) >> 24) & 0x7F;
        [$w, $h] = [imagesx($masked), imagesy($masked)];
        self::assertSame(
            [127, 0, 0, 127],
            [$alpha(0, 0), $alpha($w - 1, 0), $alpha(0, $h - 1), $alpha($w - 1, $h - 1)],
            'each quarter of the image takes its sample of the 2×2 mask'
        );
    }

    public function testAnAppendedIncrementalUpdateIsCaught(): void
    {
        // TESTING.md §5: tampering via an incremental update ("PDF shadow attack") must be caught, not just direct edits.
        $pdf = $this->sealedPdf();
        preg_match_all('/startxref\s+(\d+)/', $pdf, $m);
        $prev    = end($m[1]);
        $offset  = strlen($pdf);
        $update  = "999 0 obj\n<< /Type /Annot /Subtype /FreeText /Rect [100 100 300 150] /Contents (Approved: 10.000 EUR) >>\nendobj\n";
        $xref    = $offset + strlen($update);
        $update .= "xref\n999 1\n" . sprintf('%010d 00000 n ', $offset) . "\ntrailer\n<< /Size 1000 /Prev $prev >>\nstartxref\n$xref\n%%EOF\n";

        $html = $this->verify($pdf . $update, 'shadow.pdf');
        self::assertSame('fail', self::verdict($html));
        self::assertStringContainsString('Incremental update detected', $html);
    }

    public function testAPdfThisPluginNeverSealedIsNotVerified(): void
    {
        // TESTING.md §5: an arbitrary PDF is reported "Not Verifiable" — not a false pass, not a crash.
        // A font the release keeps: mPDF's default is one the build trims (tools/build-config.php).
        $mpdf = new \Mpdf\Mpdf(['tempDir' => $this->dir, 'default_font' => 'dejavusans']);
        $mpdf->WriteHTML('<p>Some other document</p>');
        $html = $this->verify($mpdf->Output('', 'S'), 'foreign.pdf');

        self::assertNotSame('pass', self::verdict($html));
        self::assertStringNotContainsString('Authentic', $html);
        self::assertSame('Not checked', self::problemCard($html)[2]);
    }

    public function testASealFromAKeyThisSiteNeverHadIsNotAuthentic(): void
    {
        $pdf = $this->sealedPdf();
        // A site that never held the signing key: nothing in the active key or the history matches the seal's key id.
        delete_option('fabricator_forms_seal_key');
        delete_option('fabricator_forms_seal_key_history');
        HashSeal::createInitialKey();

        $html = $this->verify($pdf, 'unknown-key.pdf');
        self::assertSame('fail', self::verdict($html));
        self::assertStringContainsString('NOT VERIFIABLE', $html);
    }

    public function testARetiredKeyStillVerifiesItsPdfs(): void
    {
        $pdf = $this->sealedPdf();
        HashSeal::rotateKey(false, true);

        self::assertSame('pass', self::verdict($this->verify($pdf, 'rotated.pdf')));
    }

    public function testHidingSignaturesAndUploadsKeepsTheFieldsAndStillVerifies(): void
    {
        // The toggle was checked together with "Form fields" and removed every field from every PDF. The hidden signature
        // stays in the seal, marked as not shown, so the verifier pairs the remaining markers with the right fields.
        update_option('fabricator_forms_pdf_layout', ['section_hidden' => ['signatures']]);
        $html = $this->verify($this->sealedPdf(true, true), 'no-signatures.pdf');

        self::assertSame('pass', self::verdict($html), self::failedRows($html));
        self::assertStringContainsString('Please deliver on Monday.', $html, 'the fields are still in the PDF');
    }

    public function testHidingTheFooterStillVerifies(): void
    {
        // The "Footer" toggle now removes the footer and its page numbers (it changed nothing before).
        update_option('fabricator_forms_pdf_layout', ['section_hidden' => ['footer']]);
        $html = $this->verify($this->sealedPdf(), 'no-footer.pdf');

        self::assertSame('pass', self::verdict($html), self::failedRows($html));
    }

    public function testHidingTheFormFieldsStillVerifies(): void
    {
        update_option('fabricator_forms_pdf_layout', ['section_hidden' => ['fields']]);
        $html = $this->verify($this->sealedPdf(), 'no-fields.pdf');

        self::assertSame('pass', self::verdict($html), self::failedRows($html));
        // Hiding is not redacting (readme, "Sealed PDFs"): the answer is still in the seal, which the verifier shows.
        self::assertStringContainsString('Please deliver on Monday.', $html);
        self::assertStringContainsString('&quot;shown&quot;: false', $html);
    }

    public function testAPdfWithASepaMandateVerifiesAsAuthentic(): void
    {
        // Found by the verifier on a real mandate: the box around it carried text in the start and end entries' cells
        // that their sealed values do not hold, so every field-content check of a SEPA PDF failed.
        $html = $this->verify($this->sealedPdf(true, 'sepa'), 'sepa.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
    }

    public function testASignatureImageCarryingMarkerBytesDoesNotMakeAGenuinePdfModified(): void
    {
        // mPDF embeds a JPEG as uploaded, comment included: one holding every keyword a whole-file scan looks for
        // ("%%EOF", a seal block, "/Type /Page", "/BaseFont", "/Annots", …) must leave the genuine PDF verifying.
        $im = imagecreatetruecolor(40, 20);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 255, 255, 255));
        ob_start();
        imagejpeg($im);
        $jpeg    = (string) ob_get_clean();
        // Fake objects too, numbered like the document's own: read as their last definitions, they would take over the page tree.
        $fakes = '';
        for ($n = 1; $n <= 40; $n++) {
            $fakes .= "\nendobj\n$n 0 obj\n<< /Type /Catalog /Pages 999 0 R >>\nendobj\n";
        }
        $comment = "%%EOF\n---BEGIN-SEAL---eyJ4IjoxfQ==---END-SEAL---\n/Type /Evil\n/BaseFont /EvilFont\n/Type /Page\n"
            . "/Font << /F9 1 0 R >>\n/Annots [3 0 R 4 0 R 5 0 R]\n/Root 2 0 R\n/XObject << /I9 1 0 R >> /SMask 1 0 R\n"
            . "/Type /FontDescriptor /FontFile2 1 0 R >>\nendobj\nxref\n0 1\ntrailer << /Root 2 0 R >>\n" . $fakes;
        $jpeg    = substr($jpeg, 0, 2) . "\xFF\xFE" . pack('n', strlen($comment) + 2) . $comment . substr($jpeg, 2);
        $pdf     = $this->sealedPdf(true, null, 'Please deliver on Monday.', 'data:image/jpeg;base64,' . base64_encode($jpeg));
        self::assertGreaterThan(1, substr_count($pdf, '%%EOF'), 'the comment is in the file as written');

        $html = $this->verify($pdf, 'jpeg-comment.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
    }

    public function testASignatureImageRepeatingObjectHeadersPastTheCeilingStillVerifies(): void
    {
        // The object ceiling counted " obj" in image data too, so a JPEG comment repeating it 50,001 times (about 200 KB,
        // in comment segments of at most 64 KB) would get the submitter's own genuine PDF refused as too large.
        $im = imagecreatetruecolor(40, 20);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 255, 255, 255));
        ob_start();
        imagejpeg($im);
        $jpeg     = (string) ob_get_clean();
        $segments = '';
        foreach (str_split(str_repeat("\nobj", PdfUtils::MAX_OBJECTS + 1), 60000) as $chunk) {
            $segments .= "\xFF\xFE" . pack('n', strlen($chunk) + 2) . $chunk;
        }
        $jpeg = substr($jpeg, 0, 2) . $segments . substr($jpeg, 2);
        $pdf  = $this->sealedPdf(true, null, 'Please deliver on Monday.', 'data:image/jpeg;base64,' . base64_encode($jpeg));
        self::assertGreaterThan(PdfUtils::MAX_OBJECTS, substr_count($pdf, "\nobj"), 'the comment is in the file as written');

        $html = $this->verify($pdf, 'many-obj.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
    }

    public function testAPdfWithALongNonAsciiAnswerVerifiesAsAuthentic(): void
    {
        // A long non-ASCII answer (48,000 characters, 9,000 of them umlauts) makes a large seal, which must still verify.
        $note = str_repeat('Grüße aus Köln, ', 3000);
        $html = $this->verify($this->sealedPdf(true, null, $note), 'long.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
    }

    public function testAnAnswerWithBidiControlsRemovedCannotFormAMarker(): void
    {
        // After U+202E RLO, mPDF lays the rest out right to left: the typed text reversed, its brackets mirrored, which
        // puts "[FABRICATOR_PDF_FIELD_END]" into the PDF's text. FormProcessor removes the controls before printing.
        $typed = "\u{05D0}\u{202E}[DNE_DLEIF_FDP_ROTACIRBAF]";
        self::assertNull(PdfUtils::reservedMarker($typed), 'nothing to see in the typed text');
        $html = $this->verify($this->sealedPdf(true, null, $typed), 'rlo.pdf');
        self::assertNotSame('pass', self::verdict($html), 'as typed, the answer forges a marker');
        $html = $this->verify($this->sealedPdf(true, null, PdfUtils::stripBidiControls($typed)), 'rlo-stripped.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
    }

    public function testAPdfSignedByTypingTheNameVerifiesAsAuthentic(): void
    {
        // The Signature field and the mandate, both signed by typing the name instead of drawing: text in the PDF,
        // sealed like any answer.
        $html = $this->verify($this->sealedPdf(true, 'sepa', 'Please deliver on Monday.', 'Ada Lovelace'), 'typed.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
        self::assertStringContainsString('Ada Lovelace (signed by typing the name)', $html, 'the typed signatures, as sealed');
    }

    public function testAPdfWithEveryMandateDetailSwitchedOnVerifiesAsAuthentic(): void
    {
        // The creditor's name and address, the payment type, the debtor's address and the place and date of signing,
        // each in a cell of its own that holds exactly its sealed value.
        $html = $this->verify($this->sealedPdf(true, 'sepa-full'), 'sepa-full.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
        self::assertStringContainsString('Hauptstr. 1, 10115 Berlin', $html);
        self::assertStringContainsString('Recurrent payment', $html);
    }

    public function testAPdfWithAnAchMandateVerifiesAsAuthentic(): void
    {
        // Another scheme's details in the box: routing number, account number and account type instead of IBAN and BIC,
        // under the admin's own wording (none ships for ACH).
        $html = $this->verify($this->sealedPdf(true, 'ach'), 'ach.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
        self::assertStringContainsString('011000015', $html);
    }

    public function testAPdfWithLinksInAnHtmlBlockVerifiesAsAuthentic(): void
    {
        // mPDF writes each link in the form author's HTML as a link annotation; an e-mail address becomes a mailto link.
        $html = $this->verify($this->sealedPdf(true, null, 'Please deliver on Monday.', null, self::LINKS_HTML), 'links.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
        self::assertStringContainsString('mailto:info@club.example', $html, 'the annotation list names the sealed link');
        self::assertStringContainsString('Annotation #1 — Link', $html, 'each annotation is named by its PDF type');
        self::assertStringNotContainsString('UNKNOWN', $html);
        self::assertStringNotContainsString('fabricator-pdf-subtoggle', $html, 'the list opens with its section, no second toggle');
    }

    public function testALongHtmlBlockIsSealedInShortLinesAndVerifies(): void
    {
        // mPDF breaks a word too long for its line in time growing with the square of the word's length, so the seal is
        // written in lines it never has to break. 31 KB of HTML text seals into some 40 lines.
        $block = str_repeat('<p>' . str_repeat('Terms and conditions. ', 20) . '</p>', 70);
        $pdf   = $this->sealedPdf(true, null, 'Please deliver on Monday.', null, $block);

        $html = $this->verify($pdf, 'long-html.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
        $lines = self::sealLines($pdf);
        self::assertGreaterThan(10, count($lines));
        self::assertLessThanOrEqual(1000 + strlen('---BEGIN-SEAL---'), max(array_map('strlen', $lines)));
    }

    public function testGenericFontNamesNeedNoCondensedFont(): void
    {
        // mPDF resolves these names to its Condensed DejaVu families, whose files the release build leaves out: the
        // generator points those families at the regular files.
        $block = '<p style="font-family:Arial">Arial</p><p style="font-family:serif">serif</p><p style="font-family:sans-serif">sans</p>';
        $pdf   = $this->sealedPdf(false, null, 'Please deliver on Monday.', null, $block);

        self::assertDoesNotMatchRegularExpression('#/BaseFont\s*/(?:[A-Z]{6}\+)?DejaVu\w*Condensed#', $pdf);
        self::assertMatchesRegularExpression('#/BaseFont\s*/(?:[A-Z]{6}\+)?DejaVuSerif\b#', $pdf, 'the serif text has its family');
        $html = $this->verify($pdf, 'generic-fonts.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
    }

    public function testCharactersTheFontsLackAreDrawnAsAPlaceholderAndTheDocumentVerifies(): void
    {
        // DejaVu lacks all of these: FreeSerif draws "ℊ", Quivira "⌭ ⚞ ⟋"; the Fraktur letters (beyond U+FFFF) are written
        // plain, and what nothing draws (CJK, emoji) is U+FFFD in PDF and seal alike. "Ŝ€", "ќ“" and "\—" put a backslash
        // byte before a space byte (separateEscapePairs()); "Martínez" holds the byte 0xAD, which is no soft hyphen.
        $pdf  = $this->sealedPdf(false, null, '漢字 𝔏𝔬𝔯𝔢𝔪 ℊ ⌭ ⚞ ⟋ ❤️ 🎀 Ελλάδα Ŝ€ ќ“ C:\\— Martínez L̾o̾ i̾');
        $html = $this->verify($pdf, 'glyphs.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
        self::assertMatchesRegularExpression('#/BaseFont\s*/(?:[A-Z]{6}\+)?FreeSerif#', $pdf, 'FreeSerif draws "ℊ"');
        self::assertMatchesRegularExpression('#/BaseFont\s*/(?:[A-Z]{6}\+)?Quivira#', $pdf, 'Quivira draws "⌭ ⚞ ⟋"');

        $text = (new \Smalot\PdfParser\Parser())->parseContent($pdf)->getText();
        self::assertStringContainsString("\u{FFFD}\u{FFFD} Lorem ℊ ⌭ ⚞ ⟋ ❤", $text);
        self::assertStringContainsString("\u{FFFD} Ελλάδα", $text);
    }

    public function testASealHoldingFiAndFlIsDrawnWithoutLigaturesAndVerifies(): void
    {
        // The default font draws "fi" and "fl" as ligatures, which read back as U+FB01/U+FB02. Base64 holds those pairs
        // only for certain byte pairs, such as a byte ending in hex 7 ("w") before 0xE2 (the first byte of "—"), which
        // ASCII text never has.
        $pdf   = $this->sealedPdf(false, null, str_repeat('w— g— 7— ', 100));
        $lines = implode('', self::sealLines($pdf));
        self::assertSame(0, substr_count($lines, "\xFB\x01") + substr_count($lines, "\xFB\x02"), 'no ligature glyph in the seal');
        self::assertGreaterThan(0, substr_count($lines, 'fi') + substr_count($lines, 'fl'), 'the seal holds the pairs');

        $html = $this->verify($pdf, 'ligatures.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
    }

    public function testAFreeTextBoxRepeatingASealedValueIsCaught(): void
    {
        // Without an appearance stream, a viewer draws a FreeText box from /DA and /IC: here a white box over the top of
        // the page. Its text repeats a sealed answer, which once let it pass as one of the document's own annotations.
        $pdf = $this->sealedPdf(false);
        $num = self::nextObjectNumber($pdf);
        $pdf = (string) preg_replace('#/Type\s*/Page(?![s\w])#', '/Type /Page /Annots [' . $num . ' 0 R]', $pdf, 1, $hits);
        self::assertSame(1, $hits, 'the PDF has a page');
        $box = $num . " 0 obj\n<< /Type /Annot /Subtype /FreeText /Rect [40 600 560 760] /Contents (Ada Lovelace)"
            . " /DA (/Helv 60 Tf 1 0 0 rg) /C [1 1 1] /IC [1 1 1] /F 4 >>\nendobj\n";

        $html = $this->verify(self::appendObjects($pdf, $box, 1), 'freetext.pdf');
        self::assertSame('fail', self::verdict($html));
        self::assertStringContainsString('Annotations', self::failedRows($html));
        self::assertStringContainsString('Annotation #1 — FreeText', $html);
    }

    /**
     * Page /Annots entries other than a plain inline array of references, and the summary row that fails for each;
     * {annot} is the annotation's object number, {array} that of an array object holding it.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function annotsEntriesBeyondAnInlineArray(): iterable
    {
        yield 'array object' => ['/Annots {array} 0 R', 'Annotations'];
        yield 'comment inside the array' => ["/Annots [ % added\n{annot} 0 R ]", 'Annotations'];
        // A viewer reads "#6F" as "o"; the checks read names as spelled, so the file fails as a whole.
        yield 'key written in code' => ['/Ann#6Fts [{annot} 0 R]', 'PDF structure'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('annotsEntriesBeyondAnInlineArray')]
    public function testAFreeTextBoxWithoutATypeIsCaughtHoweverThePageNamesIt(string $entry, string $failedRow): void
    {
        // /Type is optional in an annotation, so this box has no "/Type /Annot"; and a page may name its annotations
        // through an array object, with a comment inside the array, or under an escaped key. A viewer draws the box
        // every way.
        $pdf   = $this->sealedPdf(false);
        $annot = self::nextObjectNumber($pdf);
        $array = $annot + 1;
        $pdf   = (string) preg_replace(
            '#/Type\s*/Page(?![s\w])#',
            '/Type /Page ' . str_replace(['{annot}', '{array}'], [(string) $annot, (string) $array], $entry),
            $pdf,
            1,
            $hits
        );
        self::assertSame(1, $hits, 'the PDF has a page');
        // The array object is added in both cases; with an inline array, nothing refers to it.
        $objects = $annot . " 0 obj\n<< /Subtype /FreeText /Rect [40 600 560 760] /Contents (Ada Lovelace)"
            . " /DA (/Helv 60 Tf 1 0 0 rg) /C [1 1 1] /IC [1 1 1] /F 4 >>\nendobj\n"
            . $array . " 0 obj\n[" . $annot . " 0 R]\nendobj\n";

        $html = $this->verify(self::appendObjects($pdf, $objects, 2), 'freetext-untyped.pdf');
        self::assertSame('fail', self::verdict($html));
        self::assertStringContainsString($failedRow, self::failedRows($html));
        if (str_contains($entry, '#')) {
            // The report names what the disguised name hides, in the words of what a viewer then shows.
            self::assertStringContainsString('/Ann#6Fts, which a viewer reads as /Annots', $html);
        }
    }

    public function testALinkWhoseAddressSpellsAKeyStillVerifies(): void
    {
        // "/Annots" in a link's address is text inside a string, which a viewer never reads as a key.
        $links = str_replace('https://club.example/terms', 'https://club.example/Annots/#6Fts', self::LINKS_HTML);
        $html  = $this->verify($this->sealedPdf(true, null, 'Please deliver on Monday.', null, $links), 'link-annots.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
    }

    public function testALinkPointingElsewhereIsCaught(): void
    {
        // The link annotation is written uncompressed; the same length keeps every xref offset.
        $pdf = str_replace('mailto:info@club.example', 'mailto:evil@club.example', $this->sealedPdf(true, null, 'Please deliver on Monday.', null, self::LINKS_HTML), $hits);
        self::assertSame(1, $hits, 'the link annotation carries the address');

        $html = $this->verify($pdf, 'retargeted.pdf');
        self::assertSame('fail', self::verdict($html));
        self::assertStringContainsString('Annotations', self::failedRows($html));
        // Both halves of the change are shown: the new target, and the sealed one the PDF no longer has.
        self::assertStringContainsString('The seal lists no link to this target', $html);
        self::assertMatchesRegularExpression('#Sealed link</span><span[^>]*>MISSING FROM PDF</span>.*?mailto:info@club\.example#s', $html);
    }

    public function testALinkMadeVisibleIsCaught(): void
    {
        // A border nine points wide draws over the text under the link.
        $pdf = (string) preg_replace('#/Border \[0 0 0\]#', '/Border [0 0 9]', $this->sealedPdf(true, null, 'Please deliver on Monday.', null, self::LINKS_HTML), 1, $hits);
        self::assertSame(1, $hits, 'mPDF writes an invisible border');

        $html = $this->verify($pdf, 'bordered.pdf');
        self::assertSame('fail', self::verdict($html));
        self::assertStringContainsString('Annotations', self::failedRows($html));
        self::assertStringContainsString('This link has a visible border.', $html, 'the row says why the link fails');
        self::assertStringNotContainsString('MISSING FROM PDF', $html, 'its target is still the sealed one');
    }

    public function testASecondSealBlockIsCaught(): void
    {
        foreach (['eyJ4IjoxfQ==' => 'two-seals.pdf', '!!not base64!!' => 'garbled-seal.pdf'] as $block => $name) {
            $html = $this->verify($this->sealedPdf() . "\n---BEGIN-SEAL---" . $block . "---END-SEAL---\n", $name);
            self::assertSame('fail', self::verdict($html), $name);
            self::assertStringContainsString('More than one seal block', $html, $name);
            // A block appended after the end of the file is no page text: the checks still read the sealed one.
            self::assertStringContainsString('The checks above read seal #1, the last one in the document&#039;s text.', $html, $name);
        }
        // Each seal that can be read can be opened, to tell them apart.
        $html = $this->verify($this->sealedPdf() . "\n---BEGIN-SEAL---eyJ4IjoxfQ==---END-SEAL---\n", 'two-seals.pdf');
        self::assertSame(2, substr_count($html, "<details class='fabricator-pdf-seal-block'>"));
        self::assertStringContainsString('HMAC valid', $html);
    }

    public function testAnEditedFontProgramIsCaught(): void
    {
        $pdf = self::editStream(
            $this->sealedPdf(),
            static fn(string $dict): bool => str_contains($dict, '/Length1'),
            static fn(string $font): string => substr_replace($font, chr(ord($font[intdiv(strlen($font), 2)]) ^ 0xFF), intdiv(strlen($font), 2), 1)
        );
        $html = $this->verify($pdf, 'font-edited.pdf');
        self::assertSame('fail', self::verdict($html));
        // The Fonts and Stream fingerprint sections say what changed, not only that something did.
        self::assertStringContainsString('Embedded font file the seal does not list', $html);
        self::assertStringContainsString('Sealed font file no longer in the PDF', $html);
        self::assertStringContainsString('1 compressed part of this file', $html);
    }

    public function testAnEditedImageIsCaught(): void
    {
        $pdf = self::editStream(
            $this->sealedPdf(),
            static fn(string $dict): bool => (bool) preg_match('#/Subtype\s*/Image\b#', $dict) && !str_contains($dict, '/SMask'),
            static fn(string $pixels): string => substr_replace($pixels, str_repeat("\x00", 16), intdiv(strlen($pixels), 2), 16)
        );
        $html = $this->verify($pdf, 'image-edited.pdf');
        self::assertSame('fail', self::verdict($html));
        self::assertContains('fail', array_column(self::imageCards($html), 0), 'the edited image has a red card');
        self::assertStringContainsString('MISMATCH', $html);
    }

    /**
     * The image cards the verifier printed, as [pass|fail, the SHA-256 in the title, the card's HTML].
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private static function imageCards(string $html): array
    {
        preg_match_all(
            "#<div class='fabricator-pdf-cmp-row fabricator-pdf-cmp-row--(pass|fail) fabricator-pdf-img-card'>(.*?)</div></div></div>#s",
            $html,
            $matches,
            PREG_SET_ORDER
        );
        $cards = [];
        foreach ($matches as $m) {
            preg_match('#SHA-256 <code>([0-9a-f]{64})</code>#', $m[2], $hash);
            $cards[] = [$m[1], $hash[1] ?? '', $m[2]];
        }
        return $cards;
    }

    /**
     * Every file that gets no report ends in the same card: its name, a pill ("Not checked" for what is wrong with the
     * file, "Error" only for an internal failure) and the reason.
     */
    public function testWhatIsNoReadablePdfEndsInAProblemCard(): void
    {
        $pdf  = $this->sealedPdf();
        $html = $this->verify(substr($pdf, 0, intdiv(strlen($pdf), 2)), 'truncated.pdf');
        self::assertNull(self::verdict($html));
        // A damaged file is the file's problem: the reader is told so, under "Not checked", with no log to look up.
        self::assertSame(
            ['unchecked', 'truncated.pdf', 'Not checked', 'This file is damaged or incomplete, so its content could not be read. A PDF from this plugin reads in full: ask for the original file.'],
            self::problemCard($html)
        );

        self::assertSame(['unchecked', 'text.pdf', 'Not checked', 'This file is not a PDF.'], self::problemCard($this->verify("Just some text, named like a PDF.\n", 'text.pdf')));
        self::assertSame(['unchecked', 'empty.pdf', 'Not checked', 'This file is not a PDF.'], self::problemCard($this->verify('', 'empty.pdf')));

        $unsealed = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
        self::assertSame(
            ['unchecked', 'never-sealed.pdf', 'Not checked', 'This PDF has no FormFabricator seal, so there is nothing to check it against.'],
            self::problemCard($this->verify($unsealed, 'never-sealed.pdf'))
        );
    }
    public function testAPageContentDefinedTwiceIsCaught(): void
    {
        // A viewer finds the page content through the cross-reference table, which here points at a forged copy that
        // paints the page white; the original follows it untouched, the last definition the checks read. pdf.js draws
        // the forged copy. No text changes, so the field comparison can't see it.
        foreach (['flate' => true, 'plain' => false] as $label => $compress) {
            $pdf = $this->sealedPdf();
            preg_match_all('/(?<![0-9])(\d+) 0 obj\s*<<((?:(?!endobj).)*?)>>\s*stream\r?\n/s', $pdf, $objects, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            $forged = null;
            foreach ($objects as $object) {
                if (!str_contains($object[2][0], '/FlateDecode') || !preg_match('/\/Length (\d+)/', $object[2][0], $length)) {
                    continue;
                }
                $decoded = @gzuncompress(substr($pdf, $object[0][1] + strlen($object[0][0]), (int) $length[1]));
                if ($decoded !== false && str_contains($decoded, 'BT') && str_contains($decoded, ' Tf')) {
                    $content = $decoded . "\nq 1 1 1 rg 0 0 600 842 re f Q\n";
                    $data    = $compress ? (string) gzcompress($content) : $content;
                    $copy    = $object[1][0] . " 0 obj\n<< " . ($compress ? '/Filter /FlateDecode ' : '') . '/Length ' . strlen($data)
                        . " >>\nstream\n" . $data . "\nendstream\nendobj\n";
                    // rebuildXref() points every entry at the object's first definition: the copy.
                    $forged = self::rebuildXref(substr($pdf, 0, $object[0][1]) . $copy . substr($pdf, $object[0][1]));
                    break;
                }
            }
            self::assertNotNull($forged, 'the PDF has a page content stream');

            $html = $this->verify($forged, "defined-twice-$label.pdf");
            self::assertSame('fail', self::verdict($html), $label);
            self::assertStringContainsString('Objects defined twice', self::failedRows($html), $label);
        }
    }
    public function testABackdatedCreationDateIsCaught(): void
    {
        // The dates are written after the seal is made, so they can't be sealed; a year earlier is far outside the time
        // the generation took. Same length, so the xref table still points at every object.
        $pdf = (string) preg_replace_callback(
            '/\/CreationDate\s*\(D:(\d{4})/',
            static fn(array $m): string => str_replace($m[1], (string) ((int) $m[1] - 1), $m[0]),
            $this->sealedPdf(),
            1,
            $hits
        );
        self::assertSame(1, $hits, 'mPDF records the creation date');

        $html = $this->verify($pdf, 'backdated.pdf');
        self::assertSame('fail', self::verdict($html));
        self::assertStringContainsString('PDF Metadata', self::failedRows($html));
    }

    public function testAModificationDateLongAfterTheGenerationIsCaught(): void
    {
        // An editor saving the document a year later writes its own date there. Same length, so the xref table still
        // points at every object.
        $pdf = (string) preg_replace_callback(
            '/\/ModDate\s*\(D:(\d{4})/',
            static fn(array $m): string => str_replace($m[1], (string) ((int) $m[1] + 1), $m[0]),
            $this->sealedPdf(),
            1,
            $hits
        );
        self::assertSame(1, $hits, 'mPDF records the modification date');

        $html = $this->verify($pdf, 'postdated.pdf');
        self::assertSame('fail', self::verdict($html));
        self::assertStringContainsString('PDF Metadata', self::failedRows($html));
    }

    public function testAChangedProducerIsCaught(): void
    {
        // mPDF writes it as UTF-16BE; same length, so the xref table still points at every object.
        $pdf = (string) preg_replace('/(\/Producer\s*\(\xFE\xFF)\x00m\x00P\x00D\x00F\)/', "\$1\x00E\x00v\x00I\x00L)", $this->sealedPdf(), 1, $hits);
        self::assertSame(1, $hits, 'mPDF names itself as the producer, without a version');

        $html = $this->verify($pdf, 'producer.pdf');
        self::assertSame('fail', self::verdict($html));
        self::assertStringContainsString('PDF Metadata', self::failedRows($html));
    }

    public function testAnAddedXmpMetadataStreamIsCaught(): void
    {
        // Viewers prefer XMP metadata over /Info for the document's properties, and this plugin writes none: an XMP
        // stream linked from the catalog, written into the file in place, can only have been added afterwards.
        $pdf = $this->sealedPdf();
        $num = self::nextObjectNumber($pdf);
        $pdf = (string) preg_replace('#(/Type\s*/Catalog)#', '$1 /Metadata ' . $num . ' 0 R', $pdf, 1, $hits);
        self::assertSame(1, $hits, 'the PDF has a catalog');
        $xmp    = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns:xmp="http://ns.adobe.com/xap/1.0/" xmp:CreateDate="2001-01-01T00:00:00Z"/></rdf:RDF></x:xmpmeta>';
        $object = $num . " 0 obj\n<< /Type /Metadata /Subtype /XML /Length " . strlen($xmp) . " >>\nstream\n" . $xmp . "\nendstream\nendobj\n";

        $html = $this->verify(self::appendObjects($pdf, $object, 1), 'xmp.pdf');
        self::assertSame('fail', self::verdict($html));
    }

    /**
     * A sealed PDF of one submission, generated by the plugin as a notification would attach it.
     */
    private function sealedPdf(
        bool $withSignature = true,
        ?string $mandateScheme = null,
        string $note = 'Please deliver on Monday.',
        ?string $signature = null,
        ?string $htmlBlock = null
    ): string {
        $fields = $withSignature ? self::FIELDS : array_slice(self::FIELDS, 0, 3);
        if ($htmlBlock !== null) {
            $fields[] = ['id' => 'info', 'type' => 'html', 'label' => '', 'html_content' => $htmlBlock];
        }
        if ($mandateScheme === 'sepa') {
            $fields[] = ['id' => 'mandate', 'type' => 'directdebit', 'label' => 'Direct debit', 'creditor_id' => 'DE98ZZZ09999999999', 'mandate_ref' => 'M-0042'];
        } elseif ($mandateScheme === 'sepa-full') {
            $fields[] = [
                'id' => 'mandate', 'type' => 'directdebit', 'label' => 'Direct debit', 'creditor_id' => 'DE98ZZZ09999999999', 'mandate_ref' => 'M-0042',
                'creditor_name' => 'Club e.V.', 'creditor_address' => 'Hauptstr. 1, 10115 Berlin', 'payment_type' => 'recurrent',
                'debtor_address' => true, 'signing_place' => true,
            ];
        } elseif ($mandateScheme === 'ach') {
            $fields[] = ['id' => 'mandate', 'type' => 'directdebit', 'scheme' => 'ach', 'label' => 'Direct debit', 'ach_text' => '<p>I authorize Acme Inc. to debit my account.</p>', 'ach_creditor_id' => '1234567890', 'mandate_ref' => 'A-7'];
        }
        $form   = FormModel::save(['title' => 'Order', 'fields' => $fields, 'notifications' => [], 'settings' => []], 0, true);
        self::assertIsInt($form);
        $values = ['name' => 'Ada Lovelace', 'amount' => '250.00', 'note' => $note];
        if ($withSignature) {
            $values['sig'] = $signature ?? self::signaturePng();
        }
        if ($mandateScheme === 'sepa' || $mandateScheme === 'sepa-full') {
            // A name typed for the field's signature signs the mandate too, so one test covers both typed.
            $typed_sig         = $signature !== null && !str_starts_with($signature, 'data:') ? $signature : null;
            $values['mandate'] = ['iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'holder' => 'Ada Lovelace', 'sig' => $typed_sig ?? self::signaturePng()];
            if ($mandateScheme === 'sepa-full') {
                $values['mandate'] += ['street' => 'Main St 1', 'postcode' => '10115', 'city' => 'Berlin', 'country' => 'Germany', 'place' => 'Berlin'];
            }
        } elseif ($mandateScheme === 'ach') {
            $values['mandate'] = ['routing' => '011000015', 'account' => '123456789', 'account_type' => 'savings', 'holder' => 'Ada Lovelace', 'sig' => self::signaturePng()];
        }
        $path = Generator::generate(FieldRegistry::mapSubmission($fields, $values, [], []), $form, 'Order');
        self::assertIsString($path, 'Generator produced a PDF');
        $bytes = (string) file_get_contents($path);
        wp_delete_file($path);
        return $bytes;
    }

    /**
     * A small drawn line as the signature pad posts it: a PNG data URI.
     */
    private static function signaturePng(): string
    {
        $im = imagecreatetruecolor(40, 20);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 255, 255, 255));
        imageline($im, 2, 10, 38, 12, (int) imagecolorallocate($im, 0, 0, 0));
        ob_start();
        imagepng($im);
        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }

    /**
     * Rewrites the first page content stream (FlateDecode, holding text operators) through $edit, in place, and
     * repairs /Length and the xref table so the file stays a valid PDF.
     */
    private static function editPageContent(string $pdf, callable $edit): string
    {
        return self::editStream(
            $pdf,
            static fn(string $dict, string $decoded): bool => str_contains($decoded, 'BT') && str_contains($decoded, ' Tf'),
            $edit
        );
    }

    /**
     * Rewrites the first FlateDecode stream that $match accepts (given its dictionary and decoded bytes) through $edit,
     * in place, and repairs /Length and the xref table so the file stays a valid PDF.
     */
    private static function editStream(string $pdf, callable $match, callable $edit): string
    {
        preg_match_all('/(?<![0-9])\d+ 0 obj\s*<<((?:(?!endobj).)*?)>>\s*stream\r?\n/s', $pdf, $objects, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($objects as $object) {
            [$dict, $dictAt] = $object[1];
            if (!str_contains($dict, '/FlateDecode') || !preg_match('/\/Length (\d+)/', $dict, $length)) {
                continue;
            }
            $dataAt  = $object[0][1] + strlen($object[0][0]);
            $data    = substr($pdf, $dataAt, (int) $length[1]);
            $decoded = @gzuncompress($data);
            if ($decoded === false || !$match($dict, $decoded)) {
                continue;
            }
            $newData = (string) gzcompress($edit($decoded));
            $newDict = (string) preg_replace('/\/Length \d+/', '/Length ' . strlen($newData), $dict, 1);
            $pdf     = substr($pdf, 0, $dictAt) . $newDict . substr($pdf, $dictAt + strlen($dict), $dataAt - $dictAt - strlen($dict))
                . $newData . substr($pdf, $dataAt + strlen($data));
            return self::rebuildXref($pdf);
        }
        self::fail('no matching stream found');
    }

    /**
     * The seal's text lines as the PDF draws them, from the line holding the start marker to the one holding the end.
     *
     * @return string[]
     */
    private static function sealLines(string $pdf): array
    {
        preg_match_all('/(?<![0-9])\d+ 0 obj\s*<<((?:(?!endobj).)*?)>>\s*stream\r?\n/s', $pdf, $objects, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($objects as $object) {
            if (!str_contains($object[1][0], '/FlateDecode') || !preg_match('/\/Length (\d+)/', $object[1][0], $length)) {
                continue;
            }
            $decoded = @gzuncompress(substr($pdf, $object[0][1] + strlen($object[0][0]), (int) $length[1]));
            if ($decoded === false || !str_contains(str_replace("\0", '', $decoded), '---BEGIN-SEAL---')) {
                continue;
            }
            // mPDF draws each line as one Tj of two-byte codes, the high byte zero.
            preg_match_all('/\((.*)\)\s*Tj/', $decoded, $runs);
            $lines = [];
            foreach ($runs[1] as $run) {
                $glyphs = str_replace("\0", '', $run);
                if ($lines === [] && !str_starts_with($glyphs, '---BEGIN-SEAL---')) {
                    continue;
                }
                $lines[] = $glyphs;
                if (str_contains($glyphs, '---END-SEAL---')) {
                    break;
                }
            }
            return $lines;
        }
        self::fail('no stream draws the seal');
    }

    /**
     * The number the next added object takes: the size the xref table declares.
     */
    private static function nextObjectNumber(string $pdf): int
    {
        preg_match('/xref\n0 (\d+)\n/', $pdf, $header, 0, (int) strrpos($pdf, "\nxref\n"));
        return (int) $header[1];
    }

    /**
     * Adds $objects, $count of them numbered from nextObjectNumber(), before the xref table, counts them into the
     * table and /Size, and repairs the table so the file stays a valid PDF.
     */
    private static function appendObjects(string $pdf, string $objects, int $count): string
    {
        $num    = self::nextObjectNumber($pdf);
        $xrefAt = (int) strrpos($pdf, "\nxref\n") + 1;
        $pdf    = substr($pdf, 0, $xrefAt) . $objects . substr($pdf, $xrefAt);
        $pdf    = str_replace("xref\n0 $num\n", "xref\n0 " . ($num + $count) . "\n", $pdf);
        $pdf    = (string) preg_replace('/\/Size ' . $num . '\b/', '/Size ' . ($num + $count), $pdf);
        return self::rebuildXref($pdf);
    }

    /**
     * Recomputes the (single, classic) xref table and startxref after bytes before it moved.
     */
    private static function rebuildXref(string $pdf): string
    {
        $xrefAt = strrpos($pdf, "\nxref\n") + 1;
        preg_match('/xref\n0 (\d+)\n/', $pdf, $header, 0, $xrefAt);
        $size    = (int) $header[1];
        $head    = substr($pdf, 0, $xrefAt);
        $entries = "0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) {
            $entries .= preg_match('/(?<![0-9])' . $i . ' 0 obj/', $head, $at, PREG_OFFSET_CAPTURE)
                ? sprintf("%010d 00000 n \n", $at[0][1])
                : "0000000000 65535 f \n";
        }
        $trailer = (string) preg_replace('/startxref\s+\d+/', "startxref\n" . $xrefAt, substr($pdf, (int) strpos($pdf, 'trailer', $xrefAt)));
        return $head . "xref\n0 $size\n" . $entries . $trailer;
    }

    /**
     * Runs the verifier on $bytes as if they had just been uploaded, and returns what it printed.
     */
    private function verify(string $bytes, string $name): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $bytes);
        ob_start();
        Verificationpage::handleUpload(['name' => $name, 'tmp_name' => $path, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK]);
        return (string) ob_get_clean();
    }

    /**
     * The one problem card in the output, as [kind, file name, pill, reason].
     *
     * @return string[]
     */
    private static function problemCard(string $html): array
    {
        $found = preg_match_all(
            "#<div class='fabricator-pdf-problem fabricator-pdf-problem--(error|unchecked)'>"
            . "<div class='fabricator-pdf-problem__hdr'><span class='fabricator-pdf-problem__name'>(.*?)</span>"
            . "<span class='fabricator-pdf-problem__pill'>(.*?)</span></div>"
            . ".*?<span class='fabricator-pdf-problem__text'>(.*?)</span>#s",
            $html,
            $m,
            PREG_SET_ORDER
        );
        self::assertSame(1, $found, 'one problem card: ' . $html);
        return [$m[0][1], html_entity_decode($m[0][2], ENT_QUOTES), $m[0][3], html_entity_decode($m[0][4], ENT_QUOTES)];
    }

    private static function verdict(string $html): ?string
    {
        return preg_match('/fabricator-pdf-verdict-(pass|fail|warn)/', $html, $m) ? $m[1] : null;
    }

    /**
     * The summary rows that did not pass, for a failure message.
     */
    private static function failedRows(string $html): string
    {
        preg_match_all("/<tr class='fabricator-pdf-toggle fabricator-pdf-summary-row'.*?<\/tr>/s", $html, $rows);
        $failed = array_filter($rows[0], static fn($row) => str_contains($row, 'fabricator-pdf-row-fail'));
        return 'failed checks: ' . implode('; ', array_map(static fn($r) => trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($r))), $failed));
    }
}
