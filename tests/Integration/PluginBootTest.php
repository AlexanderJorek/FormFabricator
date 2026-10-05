<?php

namespace FabricatorForms\Tests\Integration;

/**
 * The plugin loads into a real WordPress the way WordPress loads it, and registers what the rest of the suite uses.
 */
final class PluginBootTest extends TestCase
{
    public function testThePluginIsLoadedAndInitialised(): void
    {
        self::assertTrue(defined('FABRICATOR_FORMS_VERSION'));
        self::assertTrue(class_exists(\FabricatorForms\Plugin::class));
        self::assertNotEmpty(\FabricatorForms\Fields\FieldRegistry::all(), 'field types registered');
    }

    public function testTheSuggestedPrivacyTextIsCopyableAndMentionsRecaptchaOnlyWhenUsed(): void
    {
        $html = static fn(): string => (string) (new \ReflectionMethod(\FabricatorForms\Plugin::class, 'privacyPolicyHtml'))->invoke(null);

        // Core's "Copy suggested policy text" drops .privacy-policy-tutorial: only the headings were copied.
        self::assertStringNotContainsString('privacy-policy-tutorial', $html());
        self::assertStringContainsString('<p>', $html());
        self::assertStringNotContainsString('reCAPTCHA', $html(), 'no CAPTCHA configured, nothing sent to Google');

        update_option('fabricator_forms_recaptcha_site_key', 'site-key');
        self::assertStringContainsString('reCAPTCHA', $html());
    }

    public function testTheSubmissionHandlerIsHooked(): void
    {
        self::assertNotFalse(has_action('fabricator_forms_submission'), 'MailSender listens for submissions');
    }
}
