<?php

namespace FabricatorForms\Tests\Integration\Form;

use FabricatorForms\Form\FormRenderer;
use FabricatorForms\Tests\Integration\AjaxTestCase;
use FabricatorForms\Tests\Support\Overrides;
use FabricatorForms\Utils\MemoryBudget;
use FabricatorForms\Utils\SingleUseToken;
use PHPUnit\Framework\Attributes\Group;

/**
 * What the server itself refuses or records when the browser's checks never ran (JavaScript off, a direct POST), and
 * what uploads cost (TESTING.md §3, §4). Uploaded files go through the real submission action; is_uploaded_file() accepts
 * exactly the temp files a test creates (Support\Overrides), as PHP would for a real multipart request.
 */
#[Group('package')]
final class ServerChecksTest extends AjaxTestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    /** @var array<int, array{to: mixed, attachments: array}> */
    private array $mailed = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        Overrides::$uploadedFiles = [];
        $this->mailed = [];
        // Attachment paths as wp_mail() receives them: the mock mailer keeps only the encoded message.
        add_filter('wp_mail', function (array $atts): array {
            $this->mailed[] = ['to' => $atts['to'], 'attachments' => array_values((array) $atts['attachments'])];
            return $atts;
        });
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function tear_down(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        self::setBudget(null);
        parent::tear_down();
    }

    public function testCurrencyAndNumberAreCheckedWithoutJavascript(): void
    {
        $form = $this->createForm([
            ['id' => 'amount', 'type' => 'currency', 'label' => 'Amount', 'currency' => 'EUR'],
            ['id' => 'qty', 'type' => 'number', 'label' => 'Quantity', 'step' => 1],
        ], [self::notification()]);

        $three = $this->submit($form, ['amount' => '12.345', 'qty' => '1']);
        self::assertFalse($three['success']);
        self::assertStringContainsString('at most two decimal places', $three['data']['errors']['amount'] ?? '');

        self::assertTrue($this->submit($form, ['amount' => '12.34', 'qty' => '1'])['success']);
        self::assertTrue($this->submit($form, ['amount' => '12.5', 'qty' => '1'])['success']);

        $half = $this->submit($form, ['amount' => '1', 'qty' => '2.5']);
        self::assertFalse($half['success']);
        self::assertArrayHasKey('qty', $half['data']['errors'], 'step 1 refuses 2.5');
    }

    public function testADateLimitStillAppliesAfterTheFormatChangedAndIsNamedInTheNewFormat(): void
    {
        // The limits were entered while the field was DD.MM.YYYY; the admin then switched it to YYYY-MM-DD.
        $form = $this->createForm([
            ['id' => 'when', 'type' => 'date', 'label' => 'When', 'date_format' => 'ymd', 'min_date' => '01.03.2026', 'max_date' => '31.12.2026'],
        ], [self::notification()]);

        $early = $this->submit($form, ['when' => '2026-02-28']);
        self::assertFalse($early['success']);
        self::assertSame('Please enter a date on or after 2026-03-01.', $early['data']['errors']['when']);

        $late = $this->submit($form, ['when' => '2027-01-01']);
        self::assertSame('Please enter a date on or before 2026-12-31.', $late['data']['errors']['when']);

        self::assertTrue($this->submit($form, ['when' => '2026-03-01'])['success']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function refusedUploads(): iterable
    {
        yield 'a PNG named .pdf' => ['invoice.pdf', 'png', 'does not match its file type'];
        yield 'a Windows program named .pdf' => ['invoice.pdf', 'exe', 'not allowed for security reasons'];
        yield 'zip, even when the admin allows it' => ['files.zip', 'pdf', 'is not allowed for security reasons'];
        yield 'tar, even when the admin allows it' => ['files.tar', 'pdf', 'is not allowed for security reasons'];
        yield 'gz, even when the admin allows it' => ['files.gz', 'pdf', 'is not allowed for security reasons'];
        yield '7z, even when the admin allows it' => ['files.7z', 'pdf', 'is not allowed for security reasons'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedUploads')]
    public function testADisguisedFileOrAnArchiveIsRefusedByTheServer(string $name, string $content, string $message): void
    {
        $form = $this->createForm([self::uploadField('doc', 'Document', ['allowed_types' => 'zip,tar,gz,7z'])], [self::notification(['attach_uploads' => true])]);
        $_FILES = ['doc' => $this->upload($name, self::content($content))];

        $r = $this->submit($form, []);

        self::assertFalse($r['success']);
        self::assertStringContainsString($message, $r['data']['errors']['doc'] ?? '');
        self::assertSame([], self::sentMail());
    }

    public function testARejectedFileIsNamedBackAsTheVisitorNamedIt(): void
    {
        // TESTING.md §4: "a&b.pdf" came back as "a&amp;b.pdf". The message is JSON, shown with textContent, so
        // nothing in it is HTML-escaped; the name is the one the server keeps, after WordPress's sanitize_file_name().
        $form = $this->createForm([self::uploadField('doc', 'Document')], [self::notification(['attach_uploads' => true])]);
        $_FILES = ['doc' => $this->upload('a&b.pdf', self::content('png'))];

        $r = $this->submit($form, []);

        self::assertFalse($r['success']);
        self::assertStringNotContainsString('&amp;', $r['data']['errors']['doc']);
        self::assertSame('The content of "ab.pdf" does not match its file type.', $r['data']['errors']['doc']);
    }

    public function testARealPdfIsAcceptedAndAttached(): void
    {
        $form = $this->createForm([self::uploadField('doc', 'Document')], [self::notification(['attach_uploads' => true])]);
        $_FILES = ['doc' => $this->upload('offer.pdf', self::content('pdf'))];

        $r = $this->submit($form, []);

        self::assertTrue($r['success'], wp_json_encode($r));
        self::assertSame(['offer.pdf'], array_map('basename', $this->mailed[0]['attachments']));
    }

    public function testPostDataRecordsThePostTheFormWasShownOn(): void
    {
        $author = self::factory()->user->create(['display_name' => 'Grace Author']);
        $post   = self::factory()->post->create(['post_title' => 'Summer Offer', 'post_author' => $author]);
        $form   = $this->createForm([
            ['id' => 'pd', 'type' => 'postdata', 'label' => 'Page', 'post_field' => ['post_title', 'post_url', 'post_id', 'post_author']],
        ], [self::notification()]);

        // Rendered on the post, as the shortcode is; the visitor's browser posts back what the page carried.
        $GLOBALS['post'] = get_post($post);
        $html = FormRenderer::render($form);
        unset($GLOBALS['post']);
        preg_match_all('/name="pd\[(_source_post_id|_source_sig)\]" value="([^"]*)"/', $html, $m);
        $posted = array_combine($m[1], $m[2]);
        self::assertSame((string) $post, $posted['_source_post_id'] ?? null);

        self::assertTrue($this->submit($form, ['pd' => $posted])['success']);

        $body = self::sentMail()[0]->body;
        foreach (['Summer Offer', get_permalink($post), (string) $post, 'Grace Author'] as $expected) {
            self::assertStringContainsString($expected, $body);
        }
    }

    public function testAnUploadFieldShowsTheLimitTheMemoryBudgetAllows(): void
    {
        self::setBudget(512);
        $form = $this->createForm([self::uploadField('doc', 'Document', ['max_size_mb' => 200])], [self::notification()]);

        self::assertStringContainsString('Maximum file size: 104 MB', FormRenderer::render($form));
    }

    public function testAFileOverTheBudgetsLimitIsRefusedWithTheFieldsSizeMessage(): void
    {
        self::setBudget(512);
        $form = $this->createForm([self::uploadField('doc', 'Document', ['max_size_mb' => 200])], [self::notification(['attach_uploads' => true])]);
        // The size PHP reports in $_FILES decides, before any file is read.
        $_FILES = ['doc' => $this->upload('big.pdf', self::content('pdf'), 105 * 1048576)];

        $r = $this->submit($form, []);

        self::assertFalse($r['success']);
        self::assertSame('"big.pdf" exceeds the maximum file size of 104 MB.', $r['data']['errors']['doc'] ?? null);
    }

    public function testFilesTooLargeTogetherAreRefusedAsTooLargeNotAsServerBusy(): void
    {
        self::setBudget(512);
        $form = $this->createForm([
            self::uploadField('a', 'A', ['max_size_mb' => 200]),
            self::uploadField('b', 'B', ['max_size_mb' => 200]),
        ], [self::notification(['attach_uploads' => true])]);
        $_FILES = [
            'a' => $this->upload('a.pdf', self::content('pdf'), 60 * 1048576),
            'b' => $this->upload('b.pdf', self::content('pdf'), 60 * 1048576),
        ];

        $r = $this->submit($form, []);

        self::assertFalse($r['success']);
        self::assertSame('The attached files are too large in total. Please attach fewer or smaller files.', $r['data']['message']);
    }

    public function testAFormWhoseHtmlBlocksAloneExceedTheBudgetIsRefusedAsTooLargeForItsPdf(): void
    {
        // mPDF lays an HTML block's text out in its cell and again inside the seal, at many times its size in memory.
        // The estimate counts it, here inside a group, though no visitor typed it: 536 KB is beyond a 512 MB budget.
        self::setBudget(512);
        $block  = ['id' => 'terms', 'type' => 'html', 'label' => '', 'html_content' => str_repeat('<p>' . str_repeat('Terms and conditions. ', 20) . '</p>', 1200)];
        $fields = [
            ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['id' => 'grp', 'type' => 'group', 'label' => 'Terms', 'children' => [$block]],
        ];

        $r = $this->submit($this->createForm($fields, [self::notification(['attach_pdf' => true])]), ['name' => 'Ada']);
        self::assertFalse($r['success']);
        self::assertSame('This form is too large for the server to create its PDF. Please let the site operator know.', $r['data']['message']);
        self::assertSame([], self::sentMail());

        $without_pdf = $this->createForm($fields, [self::notification()]);
        self::assertTrue($this->submit($without_pdf, ['name' => 'Ada'])['success'], 'without a PDF nothing lays the block out');
    }

    public function testASingle103MbFileGoesThroughOnAFormWithoutAPdf(): void
    {
        self::setBudget(512);
        $form = $this->createForm([self::uploadField('doc', 'Document', ['max_size_mb' => 200])], [self::notification(['attach_uploads' => true])]);
        $_FILES = ['doc' => $this->upload('large.pdf', str_pad(self::content('pdf'), 103 * 1048576, "\n"))];

        $r = $this->submit($form, []);

        self::assertTrue($r['success'], wp_json_encode($r));
        self::assertSame(103 * 1048576, filesize($this->mailed[0]['attachments'][0]));
    }

    public function testAPdfOnlyNotificationCarriesThePdfWithDocumentsAndUnreadableImagesButNoCopiesOfShownImages(): void
    {
        $form = $this->createForm([
            self::uploadField('scan', 'Scan'),
            self::uploadField('photo', 'Photo'),
            self::uploadField('doc', 'Document'),
        ], [
            self::notification(['slug' => 'pdf-only', 'to' => 'pdf@example.org', 'attach_pdf' => true, 'attach_uploads' => false]),
            self::notification(['slug' => 'both', 'to' => 'both@example.org', 'attach_pdf' => true, 'attach_uploads' => true]),
        ]);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        \FabricatorForms\PDF\HashSeal::createInitialKey();
        $_FILES = [
            'scan'  => $this->upload('scan.tiff', self::content('tiff')),
            'photo' => $this->upload('photo.jpg', self::content('jpeg')),
            'doc'   => $this->upload('terms.pdf', self::content('pdf')),
        ];

        $r = $this->submit($form, []);

        self::assertTrue($r['success'], wp_json_encode($r));
        $byRecipient = array_column($this->mailed, 'attachments', 'to');
        $pdfOnly     = array_map('basename', $byRecipient['pdf@example.org']);
        self::assertCount(3, $pdfOnly, implode(', ', $pdfOnly));
        self::assertMatchesRegularExpression('/^Entry_[0-9a-f]{32}\.pdf$/', $pdfOnly[0], 'the generated PDF');
        self::assertContains('scan.tiff', $pdfOnly, 'a TIFF the PDF cannot show goes along as a file');
        self::assertContains('terms.pdf', $pdfOnly, 'documents always go along');
        self::assertNotContains('photo.jpg', $pdfOnly, 'the photo is in the PDF, not copied beside it');
        self::assertContains('photo.jpg', array_map('basename', $byRecipient['both@example.org']), 'unless uploads are attached too');

        $text = (new \Smalot\PdfParser\Parser())->parseFile($byRecipient['pdf@example.org'][0])->getText();
        $text = str_replace(["\u{FB00}", "\u{FB01}", "\u{FB02}"], ['ff', 'fi', 'fl'], $text); // the font's ligatures
        self::assertStringContainsString('scan.tiff', $text, 'the PDF lists the TIFF by name');
    }

    public function testThePdfAndTheAttachedCopiesAreGoneWhenTheRequestEnds(): void
    {
        // TESTING.md §5. No submission data stays on disk: the generated PDF and the copies of uploads the notification
        // attaches are removed by shutdown functions registered the moment they exist, which PHP runs when the request
        // ends, after a memory or time fatal too. The test holds them back and plays the end of the request.
        Overrides::$shutdownFunctions = [];
        $form = $this->createForm(
            [self::uploadField('doc', 'Document')],
            [self::notification(['attach_pdf' => true, 'attach_uploads' => true])]
        );
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        \FabricatorForms\PDF\HashSeal::createInitialKey();
        $_FILES = ['doc' => $this->upload('terms.pdf', self::content('pdf'))];

        $r = $this->submit($form, []);

        self::assertTrue($r['success'], wp_json_encode($r));
        $attached = $this->mailed[0]['attachments'];
        self::assertSame(['Entry', 'terms.pdf'], [substr(basename($attached[0]), 0, 5), basename($attached[1])]);
        $copies = dirname($attached[1], 2);
        // One folder per kind in the plugin's folder in uploads, both named for the submission.
        $base = \FabricatorForms\Utils\PrivateDir::base();
        self::assertSame([$base . '/pdf', $base . '/mail'], [dirname($attached[0], 2), dirname($copies)]);
        self::assertSame(basename(dirname($attached[0])), basename($copies));
        foreach ($attached as $path) {
            self::assertFileExists($path, 'still there until the request ends');
        }

        Overrides::runShutdownFunctions();

        foreach ($attached as $path) {
            self::assertFileDoesNotExist($path);
        }
        self::assertDirectoryDoesNotExist($copies, 'the attachment copies\' own folder too');
        self::assertDirectoryDoesNotExist(dirname($attached[0]), 'and the PDF\'s, with mPDF\'s files in it');
    }

    public function testAnAttachmentFolderRemovedOnItsOwnIsMadeAgainBeforeTheUploadsAreAttached(): void
    {
        // Whatever removed mail/ (a cleanup tool, a restore) did so while the day-long "folders ready" transient still
        // stands: the upload must still reach the notification, not be left out of a submission reported as sent.
        \FabricatorForms\Utils\PrivateDir::prepare();
        \FabricatorForms\Utils\PrivateDir::removeTree(\FabricatorForms\Utils\PrivateDir::base() . '/mail');
        $form = $this->createForm([self::uploadField('doc', 'Document')], [self::notification(['attach_uploads' => true])]);
        $_FILES = ['doc' => $this->upload('terms.pdf', self::content('pdf'))];

        $r = $this->submit($form, []);

        self::assertTrue($r['success'], wp_json_encode($r));
        self::assertSame(['terms.pdf'], array_map('basename', $this->mailed[0]['attachments']));
        self::assertFileExists(\FabricatorForms\Utils\PrivateDir::base() . '/mail/index.php', 'hardened again');
    }

    public function testUploadsThatCannotBePreparedAsAttachmentsStopTheSubmissionBeforeAnyEmail(): void
    {
        // Nothing is stored locally: mailing the notifications without the visitor's files would lose them while the
        // submission reads as sent. A file where mail/ belongs can't be made a folder, as an unwritable uploads folder
        // or a full disk can't.
        $mail_dir = \FabricatorForms\Utils\PrivateDir::prepare() . '/mail';
        \FabricatorForms\Utils\PrivateDir::removeTree($mail_dir);
        file_put_contents($mail_dir, 'not a folder');
        $form  = $this->createForm([self::uploadField('doc', 'Document')], [self::notification(['attach_uploads' => true])]);
        $token = SingleUseToken::issue($form);
        $_FILES = ['doc' => $this->upload('terms.pdf', self::content('pdf'))];

        try {
            $r = $this->submit($form, [], $token);
        } finally {
            unlink($mail_dir);
        }

        self::assertFalse($r['success']);
        self::assertSame('Your submission could not be delivered. Please try again later.', $r['data']['message']);
        self::assertSame([], $this->mailed, 'no notification went out without the file');
        $_FILES = ['doc' => $this->upload('terms.pdf', self::content('pdf'))];
        $retry  = $this->submit($form, [], $token);
        self::assertTrue($retry['success'], 'the visitor can send it again once the folder can be made: ' . wp_json_encode($retry));
        self::assertSame(['terms.pdf'], array_map('basename', $this->mailed[0]['attachments']));
    }

    public function testANotificationWithoutAttachmentsIsSentWhateverTheAttachmentFolder(): void
    {
        // The files are prepared only for a notification that carries them.
        $mail_dir = \FabricatorForms\Utils\PrivateDir::prepare() . '/mail';
        \FabricatorForms\Utils\PrivateDir::removeTree($mail_dir);
        file_put_contents($mail_dir, 'not a folder');
        $form = $this->createForm([self::uploadField('doc', 'Document')], [self::notification(['attach_uploads' => false, 'attach_pdf' => false])]);
        $_FILES = ['doc' => $this->upload('terms.pdf', self::content('pdf'))];

        try {
            $r = $this->submit($form, []);
        } finally {
            unlink($mail_dir);
        }

        self::assertTrue($r['success'], wp_json_encode($r));
        self::assertSame([], $this->mailed[0]['attachments']);
    }

    public function testRunningOutOfMemoryOverTheFilesAsksForSmallerOnesAndLetsTheVisitorRetry(): void
    {
        // TESTING.md §3. A submission with files that runs out of memory gets the files message, not WordPress's
        // critical-error page, and its token back. The test lets the submission finish, then plays the fatal-error
        // handler as if the request had died there.
        Overrides::$shutdownFunctions = [];
        $form  = $this->createForm([self::uploadField('doc', 'Document')], [self::notification()]);
        $token = SingleUseToken::issue($form);
        $_FILES = ['doc' => $this->upload('terms.pdf', self::content('pdf'))];
        self::assertTrue($this->submit($form, [], $token)['success']);
        $oom   = ['type' => E_ERROR, 'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)'];
        $other = ['type' => E_ERROR, 'message' => 'Call to undefined function nope()'];

        self::assertSame('critical', apply_filters('wp_php_error_message', 'critical', $other), 'other errors keep WordPress\'s page');
        self::assertFalse($this->submit($form, [], $token)['success'], 'and keep the token claimed');

        $answer = json_decode((string) apply_filters('wp_php_error_message', 'critical', $oom), true);
        self::assertSame(['success' => false, 'data' => ['message' => 'The attached files are too large in total. Please attach fewer or smaller files.']], $answer);
        self::assertSame(413, apply_filters('wp_php_error_args', [], $oom)['response']);

        $_FILES = ['doc' => $this->upload('terms.pdf', self::content('pdf'))];
        $retry  = $this->submit($form, [], $token);
        self::assertTrue($retry['success'], 'the retry is no duplicate: ' . wp_json_encode($retry));
    }

    public function testWithWordPresssFatalErrorHandlerOffTheShutdownFunctionGivesTheSameAnswer(): void
    {
        Overrides::$shutdownFunctions = [];
        $form  = $this->createForm([self::uploadField('doc', 'Document')], [self::notification()]);
        $token = SingleUseToken::issue($form);
        $_FILES = ['doc' => $this->upload('terms.pdf', self::content('pdf'))];
        self::assertTrue($this->submit($form, [], $token)['success']);
        add_filter('wp_fatal_error_handler_enabled', '__return_false');

        // What error_get_last() holds after the fatal (only its message counts), for the shutdown function to read.
        @trigger_error('Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)', E_USER_WARNING);
        ob_start();
        Overrides::runShutdownFunctions();
        $printed = (string) ob_get_clean();

        self::assertStringContainsString('"The attached files are too large in total. Please attach fewer or smaller files."', $printed);
        $_FILES = ['doc' => $this->upload('terms.pdf', self::content('pdf'))];
        self::assertTrue($this->submit($form, [], $token)['success'], 'the token was released');
    }

    /**
     * An upload field as the builder saves it: with every setting, its defaults filled in.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function uploadField(string $id, string $label, array $settings = []): array
    {
        return ['id' => $id, 'type' => 'upload', 'label' => $label] + $settings + (new \FabricatorForms\Fields\UploadField())->getDefaultConfig();
    }

    /**
     * A temp file holding $bytes as PHP would have received it, as a $_FILES entry; $size overrides the size PHP
     * reports (the size checks run on it before any file is read).
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function upload(string $name, string $bytes, ?int $size = null): array
    {
        $tmp = (string) tempnam(get_temp_dir(), 'php');
        file_put_contents($tmp, $bytes);
        $this->tempFiles[]           = $tmp;
        Overrides::$uploadedFiles[] = $tmp;
        return ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => $size ?? strlen($bytes)];
    }

    /**
     * Small but real files of each kind, as fileinfo and WordPress's type checks see them.
     */
    private static function content(string $kind): string
    {
        switch ($kind) {
            case 'pdf':
                return "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
                    . "2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
            case 'exe':
                return 'MZ' . str_repeat("\x90", 58) . pack('V', 64) . "PE\0\0" . str_repeat("\0", 200);
            case 'png':
            case 'jpeg':
                $im = imagecreatetruecolor(8, 8);
                imagefill($im, 0, 0, (int) imagecolorallocate($im, 200, 30, 30));
                ob_start();
                $kind === 'png' ? imagepng($im) : imagejpeg($im);
                return (string) ob_get_clean();
            case 'tiff':
                // A 1×1 grey baseline TIFF: header, one IFD with eight entries, then the single pixel.
                $entries = [[256, 3, 1, 1], [257, 3, 1, 1], [258, 3, 1, 8], [259, 3, 1, 1], [262, 3, 1, 1], [273, 4, 1, 110], [278, 3, 1, 1], [279, 4, 1, 1]];
                $ifd     = pack('v', count($entries));
                foreach ($entries as [$tag, $type, $count, $value]) {
                    $ifd .= pack('vvV', $tag, $type, $count) . ($type === 3 ? pack('vv', $value, 0) : pack('V', $value));
                }
                return "II*\0" . pack('V', 8) . $ifd . pack('V', 0) . "\x80";
        }
        throw new \InvalidArgumentException($kind);
    }

    /**
     * Sets the memory budget as FABRICATOR_MEMORY_BUDGET_MB would (MemoryBudget reads the constant once into this memo);
     * null goes back to detecting it.
     */
    private static function setBudget(?int $mb): void
    {
        (new \ReflectionProperty(MemoryBudget::class, 'budget_memo'))->setValue(null, $mb === null ? null : $mb * 1048576);
    }
}
