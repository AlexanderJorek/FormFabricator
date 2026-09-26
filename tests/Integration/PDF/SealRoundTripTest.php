<?php

namespace FabricatorForms\Tests\Integration\PDF;

use FabricatorForms\Admin\Verificationpage;
use FabricatorForms\Fields\FieldRegistry;
use FabricatorForms\Form\FormModel;
use FabricatorForms\PDF\Generator;
use FabricatorForms\PDF\HashSeal;
use FabricatorForms\Tests\Integration\TestCase;

/**
 * A real sealed PDF from a real submission, through the real verifier: authentic as generated, and caught when its
 * bytes are edited in place or an incremental update is appended (a "shadow attack"). TESTING.md §5.
 *
 * The verdict is read from the class the verifier puts on its summary line (fabricator-pdf-verdict-pass/-fail/-warn).
 */
final class SealRoundTripTest extends TestCase
{
    private const FIELDS = [
        ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
        ['id' => 'amount', 'type' => 'currency', 'label' => 'Amount', 'currency' => 'EUR'],
        ['id' => 'note', 'type' => 'textarea', 'label' => 'Note'],
        ['id' => 'sig', 'type' => 'signature', 'label' => 'Signature'],
    ];

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
        $html = $this->verify($this->sealedPdf(), 'fresh.pdf');
        self::assertSame('pass', self::verdict($html), self::failedRows($html));
    }

    public function testAPdfWithoutAnyImageVerifiesAsAuthentic(): void
    {
        // Found by this suite: every PDF carries the background grid as an SVG, which mPDF writes as a Form XObject,
        // and the object-type check only accepted an XObject when the file also held an image. With no logo set and no
        // signature, upload or image in the submission, a genuine PDF was reported as modified.
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
        $mpdf = new \Mpdf\Mpdf(['tempDir' => $this->dir]);
        $mpdf->WriteHTML('<p>Some other document</p>');
        $html = $this->verify($mpdf->Output('', 'S'), 'foreign.pdf');

        self::assertNotSame('pass', self::verdict($html));
        self::assertStringNotContainsString('Authentic', $html);
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

    /**
     * A sealed PDF of one submission, generated by the plugin as a notification would attach it.
     */
    private function sealedPdf(bool $withSignature = true): string
    {
        $fields = $withSignature ? self::FIELDS : array_slice(self::FIELDS, 0, 3);
        $form   = FormModel::save(['title' => 'Order', 'fields' => $fields, 'notifications' => [], 'settings' => []], 0, true);
        self::assertIsInt($form);
        $values = ['name' => 'Ada Lovelace', 'amount' => '250.00', 'note' => 'Please deliver on Monday.'];
        if ($withSignature) {
            $values['sig'] = self::signaturePng();
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
