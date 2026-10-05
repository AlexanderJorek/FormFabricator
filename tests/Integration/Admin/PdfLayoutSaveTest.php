<?php

namespace FabricatorForms\Tests\Integration\Admin;

use FabricatorForms\Admin\PDFLayoutEditor;
use FabricatorForms\Tests\Integration\AjaxTestCase;

/**
 * Saving the PDF Layout. Hiding "Signatures & Uploads" or "Form fields" takes signatures out of every form's PDF at
 * once, so the save names the forms whose notifications then carry none, as the builder does for one form. A header
 * image goes into every PDF, so one larger than a PDF can take is refused, and an imported one is scaled by WordPress.
 */
final class PdfLayoutSaveTest extends AjaxTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        // The plugin loads the admin classes only when is_admin(), which the test bootstrap is not.
        PDFLayoutEditor::init();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option('fabricator_forms_pdf_layout');
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function tear_down(): void
    {
        remove_all_filters('pre_http_request');
        parent::tear_down();
    }

    public function testAHeaderImageLargerThanAPdfTakesIsRefusedAndTheLayoutKept(): void
    {
        update_option('fabricator_forms_pdf_layout', ['footer_text' => 'Acme GmbH']);
        $huge = self::pngAttachment(30000, 30000);

        $r = $this->saveHeaderImage(wp_get_attachment_url($huge));

        self::assertFalse($r['success']);
        self::assertStringContainsString('megapixels', (string) ($r['data']['message'] ?? ''));
        self::assertSame(['footer_text' => 'Acme GmbH'], get_option('fabricator_forms_pdf_layout'), 'nothing written');
    }

    public function testAHeaderImageAPdfTakesIsSaved(): void
    {
        $url = wp_get_attachment_url(self::pngAttachment(600, 200));

        $r = $this->saveHeaderImage($url);

        self::assertTrue($r['success'], (string) wp_json_encode($r));
        self::assertSame($url, get_option('fabricator_forms_pdf_layout')['header_layout']['elements'][0]['src']);
    }

    public function testAnImportedHeaderImageIsScaledDownByWordPress(): void
    {
        $im = imagecreatetruecolor(3000, 60);
        ob_start();
        imagejpeg($im);
        $jpeg = (string) ob_get_clean();
        // The import downloads to a file; answer as the remote server would, without a network.
        add_filter('pre_http_request', static function ($pre, array $args) use ($jpeg) {
            if (!empty($args['filename'])) {
                file_put_contents($args['filename'], $jpeg);
            }
            return ['headers' => ['content-type' => 'image/jpeg'], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => $args['filename'] ?? null];
        }, 10, 2);

        $r = $this->saveHeaderImage('https://images.example.com/banner.jpg');

        self::assertTrue($r['success'], (string) wp_json_encode($r));
        $src  = get_option('fabricator_forms_pdf_layout')['header_layout']['elements'][0]['src'];
        $size = wp_getimagesize((string) get_attached_file(attachment_url_to_postid($src)));
        self::assertSame(2560, $size[0] ?? null, 'the PDF reads the scaled copy');
    }

    public function testHidingSignaturesOrEveryFieldNamesTheFormsWhoseRecipientsThenGetNone(): void
    {
        $sig = ['id' => 'sig', 'type' => 'signature', 'label' => 'Signature'];
        $pdf = $this->createForm([$sig], [self::notification(['attach_pdf' => true])]);
        wp_update_post(['ID' => $pdf, 'post_title' => 'Membership']);
        $files = $this->createForm([$sig], [self::notification(['attach_pdf' => true, 'attach_uploads' => true])]);
        wp_update_post(['ID' => $files, 'post_title' => 'Files too']);

        self::assertSame('', $this->save('')['data']['warning'], 'the PDF shows them');
        foreach (['signatures', 'fields'] as $hidden) {
            $warning = $this->save($hidden)['data']['warning'];
            self::assertStringContainsString('get none: Membership.', $warning, $hidden);
            self::assertStringNotContainsString('Files too', $warning, 'its files reach the recipient');
        }

        ob_start();
        PDFLayoutEditor::render();
        $page = (string) ob_get_clean();
        self::assertMatchesRegularExpression('/id="fabricator-pdf-signature-warning" class="notice notice-warning inline" >\s*<p>[^<]*get none: Membership\./', $page, 'and says so on the page');
    }

    /**
     * Saves a header with one image element showing $src, through the AJAX action.
     */
    private function saveHeaderImage(string $src): array
    {
        return $this->ajax('fabricator_save_pdf_layout', [
            'fabricator_pdf_layout_nonce' => wp_create_nonce('fabricator_pdf_layout'),
            'header_layout_json'          => wp_json_encode(['rows' => 8, 'elements' => [
                ['id' => 'e1', 'type' => 'image', 'src' => $src, 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 4],
            ]]),
        ]);
    }

    /**
     * A Media Library PNG that says it is $width × $height pixels: a header and no image data, as nothing here decodes it.
     */
    public static function pngAttachment(int $width, int $height): int
    {
        $chunk = static fn(string $type, string $data): string
            => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $png   = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)) . $chunk('IEND', '');
        $file  = wp_upload_dir()['path'] . '/header-' . wp_generate_password(6, false) . '.png';
        file_put_contents($file, $png);
        return self::factory()->attachment->create_object(['file' => $file, 'post_mime_type' => 'image/png']);
    }

    /**
     * Saves the layout through the AJAX action, with these sections hidden.
     */
    private function save(string $hidden): array
    {
        $r = $this->ajax('fabricator_save_pdf_layout', [
            'fabricator_pdf_layout_nonce' => wp_create_nonce('fabricator_pdf_layout'),
            'section_hidden'              => $hidden,
        ]);
        self::assertTrue($r['success'], wp_json_encode($r));
        return $r;
    }
}
