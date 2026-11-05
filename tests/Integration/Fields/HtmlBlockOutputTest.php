<?php

namespace FabricatorForms\Tests\Integration\Fields;

use FabricatorForms\Fields\FieldRegistry;
use FabricatorForms\PDF\Generator;
use FabricatorForms\PDF\HashSeal;
use FabricatorForms\Tests\Integration\AjaxTestCase;

/**
 * An HTML block on the page, in the notification and in the PDF: its label only while "Hide label" is off, and in the
 * mail's field list the same single blank line around it as around every other entry.
 */
final class HtmlBlockOutputTest extends AjaxTestCase
{
    private const NAME = ['id' => 'name', 'type' => 'text', 'label' => 'Name'];

    /** A paragraph whose style sets both outer margins to 0, in either order. */
    private const BOTH_MARGINS = '<p style="(?=[^"]*margin-top:0;)(?=[^"]*margin-bottom:0;)[^"]*">';

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        if (HashSeal::activeKeyProblem() !== '') {
            HashSeal::createInitialKey();
        }
    }

    public function testAHiddenLabelStaysOutOfMailAndPdfAndAShownOneIsInBoth(): void
    {
        foreach ([true, false] as $hidden) {
            $fields = [self::block(['hide_label' => $hidden]), self::NAME];
            $what   = $hidden ? 'hidden' : 'shown';

            $mail = $this->mailBody($fields);
            $pdf  = $this->pdfText($fields);

            self::assertStringContainsString('Read the terms.', $mail, "$what: the content is in the mail");
            self::assertStringContainsString('Read the terms.', $pdf, "$what: the content is in the PDF");
            self::assertSame(!$hidden, str_contains($mail, 'Terms heading'), "$what: the label in the mail");
            self::assertSame(!$hidden, str_contains($pdf, 'Terms heading'), "$what: the label in the PDF");
        }
    }

    public function testThePageShowsTheLabelUnlessHidden(): void
    {
        $handler = FieldRegistry::get('html');

        $shown  = $handler->render(self::block(['hide_label' => false]), 'terms');
        $hidden = $handler->render(self::block(['hide_label' => true]), 'terms');

        self::assertStringContainsString('<div class="fabricator-label">Terms heading</div><p>Read the terms.</p>', $shown);
        self::assertStringNotContainsString('Terms heading', $hidden);
        self::assertStringContainsString('Read the terms.', $hidden);
    }

    public function testANewBlockStartsWithItsLabelHidden(): void
    {
        $default = FieldRegistry::get('html')->getDefaultConfig();
        self::assertTrue($default['hide_label']);
        $block = ['id' => 'info', 'type' => 'html'] + $default;

        self::assertStringNotContainsString((string) $default['label'], $this->mailBody([$block, self::NAME]));
    }

    public function testTheMailPutsOneBlankLineAroundABlockLikeAroundEveryAnswer(): void
    {
        // A paragraph's own margins would add a second blank line below the block and one between label and content.
        $labeled = $this->mailBody([self::NAME, self::block(['hide_label' => false]), self::text('after')]);
        self::assertMatchesRegularExpression('#<strong>Terms heading</strong><br>' . self::BOTH_MARGINS . 'Read the terms\.</p><br><strong>After</strong>#', $labeled);
        self::assertStringContainsString('Ada<br><br><strong>Terms heading</strong>', $labeled, 'the entry before ends as usual');

        $unlabeled = $this->mailBody([self::block([]), self::NAME]);
        self::assertMatchesRegularExpression('#' . self::BOTH_MARGINS . 'Read the terms\.</p><br><strong>Name</strong>#', $unlabeled);

        // Only the outer edges: the gap between the author's own paragraphs stays, and so does a margin they set.
        $two = $this->mailBody([self::block(['html_content' => '<p style="margin-bottom:24px">One</p><p>Two</p>']), self::NAME]);
        self::assertMatchesRegularExpression('#<p style="margin-top:0;margin-bottom:\s*24px;?">One</p><p style="margin-bottom:0;">Two</p><br><strong>Name#', $two);

        // Content that doesn't end in a block still ends its line with the usual breaks.
        $inline = $this->mailBody([self::block(['html_content' => 'Plain <strong>text</strong>']), self::NAME]);
        self::assertStringContainsString('Plain <strong>text</strong><br><br><strong>Name</strong>', $inline);
    }

    /**
     * An HTML block whose label differs from its content; $overrides replaces any key.
     */
    private static function block(array $overrides): array
    {
        return $overrides + [
            'id'             => 'terms',
            'type'           => 'html',
            'label'          => 'Terms heading',
            'hide_label'     => true,
            'html_content'   => '<p>Read the terms.</p>',
            'show_in_output' => true,
        ];
    }

    private static function text(string $id): array
    {
        return ['id' => $id, 'type' => 'text', 'label' => ucfirst($id)];
    }

    /**
     * The HTML body of the notification a submission of $fields sends.
     */
    private function mailBody(array $fields): string
    {
        reset_phpmailer_instance();
        $form = $this->createForm($fields, [self::notification()]);
        $sent = $this->submit($form, ['name' => 'Ada', 'after' => 'Later']);
        self::assertTrue($sent['success'], (string) wp_json_encode($sent));
        $mail = self::sentMail();
        self::assertCount(1, $mail);
        return (string) $mail[0]->body;
    }

    /**
     * The text of the PDF a submission of $fields gets.
     */
    private function pdfText(array $fields): string
    {
        $form = $this->createForm($fields, [self::notification()]);
        $path = Generator::generate(FieldRegistry::mapSubmission($fields, ['name' => 'Ada'], [], []), $form, 'Terms');
        self::assertIsString($path, 'Generator produced a PDF');
        $bytes = (string) file_get_contents($path);
        wp_delete_file($path);
        return (new \Smalot\PdfParser\Parser())->parseContent($bytes)->getText();
    }
}
