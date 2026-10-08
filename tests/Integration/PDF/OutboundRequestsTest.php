<?php

namespace FabricatorForms\Tests\Integration\PDF;

use FabricatorForms\Fields\FieldRegistry;
use FabricatorForms\Form\FormModel;
use FabricatorForms\PDF\Generator;
use FabricatorForms\PDF\HashSeal;
use FabricatorForms\Tests\Integration\Support\RequestRecorder;
use FabricatorForms\Tests\Integration\TestCase;
use FabricatorForms\Utils\HtmlSanitizer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Building a PDF makes no request the form author's HTML asks for (TESTING.md §4), and the Direct Debit Mandate
 * validates an IBAN offline. mPDF's own curl bypasses pre_http_request, so the "attacker" host is a local logging
 * server (Support\RequestRecorder).
 */
#[Group('package')]
final class OutboundRequestsTest extends TestCase
{
    private RequestRecorder $server;

    public function set_up(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP_UnitTestCase fixture method.
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        if (HashSeal::activeKeyProblem() !== '') {
            HashSeal::createInitialKey();
        }
        $this->server = new RequestRecorder();
    }

    public function tear_down(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP_UnitTestCase fixture method.
    {
        $this->server->stop();
        parent::tear_down();
    }

    public function testTheDetectorSeesAnUnguardedMpdfFetch(): void
    {
        // Control: mPDF on its own does fetch a remote <img>. Without this, "no connection" below would prove nothing.
        $tmp  = get_temp_dir() . 'fabricator-mpdf-' . wp_generate_password(6, false);
        // A font the release keeps: mPDF's default is one the build trims (tools/build-config.php).
        $mpdf = new \Mpdf\Mpdf(['tempDir' => $tmp, 'curlTimeout' => 2, 'curlExecutionTimeout' => 5, 'default_font' => 'dejavusans']);
        $mpdf->WriteHTML('<img src="http://' . $this->server->host . '/control.png">');
        $mpdf->Output('', 'S');

        self::assertSame(['/control.png'], $this->server->requests(), 'mPDF fetched the control image');
    }

    public function testTheHtmlBlockCannotMakeThePdfStepFetchAnything(): void
    {
        $h    = 'http://' . $this->server->host;
        $html = '<p style="background:url(' . $h . '/latest/x(y)">parenthesis inside url()</p>'
            . '<p style="background:url(' . $h . '/latest/x">unterminated url(</p>'
            . '<p style="background:url(&quot;' . $h . '/entity&quot;)">entity-quoted url()</p>'
            . '<p><img src="' . $h . '/x?a=\'"></p>'
            . '<p><img src="//' . $this->server->host . '/protocol-relative.png"></p>';
        $fields = [['id' => 'block', 'type' => 'html', 'label' => '', 'html_content' => $html, 'show_in_output' => true]];
        $form   = FormModel::save(['title' => 'SSRF', 'fields' => $fields, 'notifications' => [], 'settings' => []], 0, true);
        self::assertIsInt($form);

        $path = Generator::generate(FieldRegistry::mapSubmission($fields, [], [], []), $form, 'SSRF');

        self::assertIsString($path, 'the PDF is still built');
        wp_delete_file($path);
        self::assertSame([], $this->server->requests(), 'the PDF step fetched from a host the form author named');
    }

    public function testAnImageFromTheMediaLibraryAndARelativeOneInAnHtmlBlockAppearInThePdf(): void
    {
        // TESTING.md §4. The site's own images stay: one from the Media Library (an absolute URL on the site's host) and
        // a relative one, which mPDF resolves against the address of the request (admin-ajax.php) as a browser would.
        // The site is served by a local web server holding both files.
        $docroot = get_temp_dir() . 'fabricator-site-' . wp_generate_password(6, false);
        $png     = self::png();
        wp_mkdir_p($docroot . '/wp-content/uploads/2026/10');
        wp_mkdir_p($docroot . '/wp-admin/images');
        file_put_contents($docroot . '/wp-content/uploads/2026/10/logo.png', $png);
        file_put_contents($docroot . '/wp-admin/images/logo.png', $png);
        $site = new RequestRecorder($docroot);
        $server_before = $_SERVER;
        try {
            update_option('home', 'http://' . $site->host);
            update_option('siteurl', 'http://' . $site->host);
            $_SERVER['HTTP_HOST']   = $site->host;
            $_SERVER['SCRIPT_NAME'] = '/wp-admin/admin-ajax.php';
            $images = static function (string $html): int {
                $fields = [['id' => 'block', 'type' => 'html', 'label' => '', 'html_content' => $html, 'show_in_output' => true]];
                $form   = FormModel::save(['title' => 'Images', 'fields' => $fields, 'notifications' => [], 'settings' => []], 0, true);
                $path   = Generator::generate(FieldRegistry::mapSubmission($fields, [], [], []), (int) $form, 'Images');
                self::assertIsString($path, 'the PDF is built');
                $count = preg_match_all('#/Subtype\s*/Image\b#', (string) file_get_contents($path));
                wp_delete_file($path);
                return (int) $count;
            };

            $none = $images('<p>No pictures</p>');
            $both = $images('<p><img src="http://' . $site->host . '/wp-content/uploads/2026/10/logo.png" width="40"></p>'
                . '<p><img src="images/logo.png" width="40"></p>');

            // Each of the PDF's two layout passes fetches them again.
            $fetched = array_values(array_unique($site->requests()));
            sort($fetched);
            self::assertSame(['/wp-admin/images/logo.png', '/wp-content/uploads/2026/10/logo.png'], $fetched, 'from the site itself');
            self::assertSame($none + 2, $both, 'both images are drawn in the PDF');
        } finally {
            $_SERVER = $server_before;
            $site->stop();
            \FabricatorForms\Utils\PrivateDir::removeTree($docroot);
            @rmdir($docroot);
        }
    }

    public function testTheDirectDebitFieldValidatesAnIbanWithoutAnyRequest(): void
    {
        $requests = [];
        $record   = static function ($pre, $args, $url) use (&$requests) {
            $requests[] = $url;
            return new \WP_Error('blocked', 'no outbound requests in this test');
        };
        add_filter('pre_http_request', $record, 10, 3);

        $field  = FieldRegistry::get('directdebit');
        $config = array_merge($field->getDefaultConfig(), ['id' => 'mandate', 'label' => 'Mandate']);
        $field->validate(['iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'holder' => 'Ada Lovelace'], $config);
        $field->validate(['iban' => 'DE00370400440532013000', 'bic' => 'COBADEFFXXX', 'holder' => 'Ada Lovelace'], $config);
        // Nor any other scheme's details: a sort code or routing number is checked offline, never looked up.
        $field->validate(['sort_code' => '123456', 'account' => '12345678', 'holder' => 'Ada Lovelace'], ['scheme' => 'bacs', 'bacs_text' => '<p>Pay Acme Ltd.</p>'] + $config);
        $field->validate(['routing' => '011000015', 'account' => '123456789', 'account_type' => 'checking', 'holder' => 'Ada Lovelace'], ['scheme' => 'ach', 'ach_text' => '<p>I authorize Acme Inc.</p>'] + $config);

        remove_filter('pre_http_request', $record, 10);
        self::assertSame([], $requests);
    }

    public function testUseMayOnlyReferenceTheDocumentItself(): void
    {
        // HtmlSanitizer's <use> narrowing, through the real wp_kses().
        $kept     = HtmlSanitizer::sanitize('<svg><use href="#icon-star"></use></svg>');
        $stripped = HtmlSanitizer::sanitize('<svg><use xlink:href="data:image/svg+xml;base64,AAA#x\'"></use><use href="https://evil.example/s.svg#a"></use></svg>');

        self::assertStringContainsString('#icon-star', $kept);
        self::assertStringNotContainsString('data:', $stripped);
        self::assertStringNotContainsString('evil.example', $stripped);
    }

    /**
     * A small real PNG, as a logo would be.
     */
    private static function png(): string
    {
        $im = imagecreatetruecolor(16, 16);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 30, 90, 200));
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }
}
