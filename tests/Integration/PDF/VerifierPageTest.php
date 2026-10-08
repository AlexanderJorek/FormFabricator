<?php

namespace FabricatorForms\Tests\Integration\PDF;

use FabricatorForms\Admin\Verificationpage;
use FabricatorForms\Fields\FieldRegistry;
use FabricatorForms\Form\FormModel;
use FabricatorForms\PDF\Generator;
use FabricatorForms\PDF\HashSeal;
use FabricatorForms\Tests\Integration\AjaxTestCase;
use FabricatorForms\Tests\Support\Overrides;
use FabricatorForms\Utils\MemoryBudget;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Group;

/**
 * The verifier page around the checks SealRoundTripTest covers (TESTING.md §5): what it lists when a part of the file is
 * compressed in a way this plugin never writes, what it leaves in the protected folder once a check is done, how it
 * answers while the memory budget is taken, and a file far past the object ceiling on a host with little memory.
 */
#[Group('package')]
final class VerifierPageTest extends AjaxTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        if (HashSeal::activeKeyProblem() !== '') {
            HashSeal::createInitialKey();
        }
        // The plugin loads the admin classes only when is_admin(), which the test bootstrap is not; hook them as
        // admin-ajax.php would.
        Verificationpage::register();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function tear_down(): void
    {
        (new \ReflectionProperty(MemoryBudget::class, 'budget_memo'))->setValue(null, null);
        unset($_SERVER['REQUEST_METHOD']);
        parent::tear_down();
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function foreignCompressions(): iterable
    {
        yield 'LZW' => ['/LZWDecode', 'LZWDecode'];
        yield 'RunLength' => ['/RunLengthDecode', 'RunLengthDecode'];
        yield 'compressed twice' => ['[/FlateDecode /FlateDecode]', 'FlateDecode, FlateDecode'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignCompressions')]
    public function testAPartCompressedInAWayThisPluginNeverUsesIsListedWhileTheRestIsStillChecked(string $filter, string $shown): void
    {
        $pdf = self::refilterImage($this->sealedPdf(), $filter, $object);

        $html = $this->check($pdf, 'refiltered.pdf');

        self::assertSame('fail', self::verdict($html));
        self::assertStringContainsString('compressed in a way FormFabricator never uses', $html);
        self::assertStringContainsString("Image in object $object (40 × 20 pixels): $shown", $html);
        self::assertStringContainsString('Ada Lovelace', $html, 'the field comparison still reports');
        self::assertStringContainsString('fabricator-pdf-section-', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusedUploadsAreLoggedWithoutTheirNames(): void
    {
        // A file name often names a person; the debug log, kept indefinitely, records it only as fabricator_log_file() does.
        $log = (string) tempnam(get_temp_dir(), 'log');
        $previous_log = ini_set('error_log', $log);
        $spoofed = (string) tempnam(get_temp_dir(), 'php'); // not an upload PHP received
        $notPdf  = (string) tempnam(get_temp_dir(), 'php');
        file_put_contents($spoofed, '%PDF-1.4');
        file_put_contents($notPdf, 'plain text, not a PDF');
        Overrides::$uploadedFiles = [$notPdf];
        $_FILES = ['pdfs' => [
            'name'     => ['Antrag_Max_Mustermann.pdf', 'Antrag_Erika_Musterfrau.pdf'],
            'type'     => ['application/pdf', 'application/pdf'],
            'tmp_name' => [$spoofed, $notPdf],
            'error'    => [0, 0],
            'size'     => [8, 21],
        ]];
        $_POST  = $_REQUEST = ['fabricator_verifier_nonce' => wp_create_nonce('fabricator_verifier_upload'), '_wp_http_referer' => '/wp-admin/'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        add_filter('wp_redirect', static function (string $location): string {
            throw new \RuntimeException($location);
        });
        try {
            Verificationpage::handleUploadPost();
        } catch (\RuntimeException $e) {
            // the Post/Redirect/Get answer
        } finally {
            // Read, then gone with the two stand-in uploads, whatever the handler did; later lines log where they did.
            $logged = (string) file_get_contents($log);
            ini_set('error_log', (string) $previous_log);
            array_map('wp_delete_file', [$log, $spoofed, $notPdf]);
        }

        self::assertSame(2, substr_count($logged, 'upload skipped for file #'), $logged);
        self::assertStringNotContainsString('Mustermann', $logged);
        self::assertStringNotContainsString('Musterfrau', $logged);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testACheckRunsOnAStoredUpload(): array
    {
        // Uploaded as the page posts it, then checked through the AJAX action the page calls next. This process ends
        // afterwards like the request would, running what the check left for the end of the request.
        $bytes = $this->sealedPdf();
        $tmp   = (string) tempnam(get_temp_dir(), 'php');
        file_put_contents($tmp, $bytes);
        Overrides::$uploadedFiles = [$tmp];
        $_FILES = ['pdfs' => ['name' => ['order.pdf'], 'type' => ['application/pdf'], 'tmp_name' => [$tmp], 'error' => [0], 'size' => [strlen($bytes)]]];
        $_POST  = $_REQUEST = ['fabricator_verifier_nonce' => wp_create_nonce('fabricator_verifier_upload'), '_wp_http_referer' => '/wp-admin/'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        add_filter('wp_redirect', static function (string $location): string {
            throw new \RuntimeException($location);
        });
        try {
            Verificationpage::handleUploadPost();
            self::fail('the upload ends in a redirect (Post/Redirect/Get)');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('page=fabricator-pdf-verification', $e->getMessage());
        }
        $batch = get_transient('fabricator_vbatch_' . get_current_user_id());
        $token = $batch['queue'][0]['token'];
        $copy  = get_transient('fabricator_pdf_' . $token)['path'];
        self::assertFileExists($copy, 'the upload is kept for the check');

        $imageDir = wp_upload_dir()['basedir'] . '/formfabricator/verimages/*';
        $before   = glob($imageDir) ?: [];

        $r = $this->ajax('fabricator_verify_push_lines', ['nonce' => wp_create_nonce('fabricator_verifier_nonce'), 'pdf_token' => $token, 'visualLines' => '[]']);

        self::assertTrue($r['success'], $r['raw']);
        self::assertStringContainsString('data:image/png;base64,', $r['data']['html'], 'the signature image was taken out of the PDF for the report');
        // Data protection: the image is a submitter's signature, needed only until the report holds it.
        self::assertSame([], array_values(array_diff(glob($imageDir) ?: [], $before)), 'its file was deleted once the report held it');
        return [$copy];
    }

    /**
     * @param string[] $left What the check had on disk when it answered.
     */
    #[Depends('testACheckRunsOnAStoredUpload')]
    public function testTheCheckedCopyIsGoneOnceTheCheckingRequestEnded(array $left): void
    {
        self::assertNotEmpty($left);
        foreach ($left as $file) {
            self::assertFileDoesNotExist($file);
        }
    }

    public function testWhileOtherChecksHoldTheBudgetTheAnswerIsBusyWithARetry(): void
    {
        self::setBudget(512);
        $other = MemoryBudget::reserve(480 * 1048576, 600, true);
        self::assertIsString($other);

        $r = $this->ajax('fabricator_verify_push_lines', ['nonce' => wp_create_nonce('fabricator_verifier_nonce'), 'pdf_token' => $this->storedCopy()]);

        self::assertFalse($r['success']);
        self::assertSame('Server busy verifying other PDFs right now.', $r['data']['message']);
        self::assertSame('busy', $r['data']['code']);
        self::assertSame(8, $r['data']['retry_after'], 'the page retries by itself');
        MemoryBudget::releaseReservation($other);
    }

    public function testAPdfLargerThanTheWholeBudgetSaysSoInsteadOfBusy(): void
    {
        self::setBudget(64);

        $r = $this->ajax('fabricator_verify_push_lines', ['nonce' => wp_create_nonce('fabricator_verifier_nonce'), 'pdf_token' => $this->storedCopy()]);

        self::assertFalse($r['success']);
        self::assertSame('too_large', $r['data']['code']);
        self::assertSame(0, $r['data']['retry_after']);
        self::assertStringContainsString('FABRICATOR_MEMORY_BUDGET_MB', $r['data']['message']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAFileFarPastTheObjectCeilingIsRefusedWithinAFewSecondsOnATightMemoryLimit(): void
    {
        // A host at the plugin's memory ceiling: whatever the file holds, it must end in a refusal, not a PHP fatal.
        $pdf = "%PDF-1.4\n";
        for ($i = 1; $i <= 60000; $i++) {
            $pdf .= "$i 0 obj\n<< >>\nendobj\n";
        }
        $pdf .= "trailer\n<< /Root 1 0 R >>\n%%EOF\n---BEGIN-SEAL---x---END-SEAL---\n";
        ini_set('memory_limit', (string) (memory_get_usage(true) + 64 * 1048576));

        $started = microtime(true);
        $html    = $this->check($pdf, 'many-parts.pdf');

        self::assertLessThan(5.0, microtime(true) - $started);
        self::assertStringContainsString("<span class='fabricator-pdf-problem__name'>many-parts.pdf</span>", $html);
        self::assertStringContainsString('This file has far more parts than any document this plugin creates and was not read.', $html);
    }

    /**
     * A sealed PDF of one submission with a signature image, as a notification would attach it.
     */
    private function sealedPdf(): string
    {
        $fields = [
            ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['id' => 'sig', 'type' => 'signature', 'label' => 'Signature'],
        ];
        $form = FormModel::save(['title' => 'Order', 'fields' => $fields, 'notifications' => [], 'settings' => []], 0, true);
        self::assertIsInt($form);
        $im = imagecreatetruecolor(40, 20);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 255, 255, 255));
        imageline($im, 2, 10, 38, 12, (int) imagecolorallocate($im, 0, 0, 0));
        ob_start();
        imagepng($im);
        $values = ['name' => 'Ada Lovelace', 'sig' => 'data:image/png;base64,' . base64_encode((string) ob_get_clean())];
        $path   = Generator::generate(FieldRegistry::mapSubmission($fields, $values, [], []), $form, 'Order');
        self::assertIsString($path, 'Generator produced a PDF');
        $bytes = (string) file_get_contents($path);
        wp_delete_file($path);
        return $bytes;
    }

    /**
     * $pdf with the signature image's /Filter replaced by $filter, the xref table rebuilt; $object gets its number.
     */
    private static function refilterImage(string $pdf, string $filter, ?string &$object = null): string
    {
        self::assertSame(1, preg_match('/(?<![0-9])(\d+) 0 obj\s*<<(?:(?!endobj).)*?\/Subtype\s*\/Image(?:(?!endobj).)*?\/Filter\s*(\/FlateDecode)/s', $pdf, $m, PREG_OFFSET_CAPTURE));
        $object = $m[1][0];
        $pdf    = substr_replace($pdf, $filter, $m[2][1], strlen($m[2][0]));
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
     * Runs the verifier's check on $bytes as the AJAX action does for a stored upload, and returns what it printed.
     */
    private function check(string $bytes, string $name): string
    {
        $path = get_temp_dir() . wp_generate_password(8, false) . '-' . $name;
        file_put_contents($path, $bytes);
        ob_start();
        try {
            Verificationpage::handleUpload(['name' => $name, 'tmp_name' => $path, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK]);
        } finally {
            $html = (string) ob_get_clean();
            unlink($path);
        }
        return $html;
    }

    /**
     * A sealed PDF stored as an upload waiting for its check, as handleUploadPost() leaves it; returns its token.
     */
    private function storedCopy(): string
    {
        $dir = wp_upload_dir()['basedir'] . '/formfabricator/verfiles';
        wp_mkdir_p($dir);
        $path = $dir . '/' . bin2hex(random_bytes(8)) . '-order.pdf';
        file_put_contents($path, $this->sealedPdf());
        $token = bin2hex(random_bytes(16));
        set_transient('fabricator_pdf_' . $token, ['path' => $path, 'uid' => get_current_user_id()], 600);
        return $token;
    }

    private static function verdict(string $html): ?string
    {
        return preg_match('/fabricator-pdf-verdict-(pass|fail|warn)/', $html, $m) ? $m[1] : null;
    }

    private static function setBudget(int $mb): void
    {
        (new \ReflectionProperty(MemoryBudget::class, 'budget_memo'))->setValue(null, $mb * 1048576);
    }
}
