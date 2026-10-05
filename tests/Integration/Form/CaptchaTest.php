<?php

namespace FabricatorForms\Tests\Integration\Form;

use FabricatorForms\Fields\CaptchaField;
use FabricatorForms\Tests\Integration\AjaxTestCase;

/**
 * A submission with a reCAPTCHA field, with Google's siteverify answered through pre_http_request (TESTING.md §3): a
 * token Google accepts for this site passes; a refused token, a token minted on another host, or siteverify out of reach
 * refuses the submission with the CAPTCHA's own error. Real keys against Google's service stay a manual check.
 */
final class CaptchaTest extends AjaxTestCase
{
    private const FIELDS = [
        ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
        ['id' => 'cap', 'type' => 'captcha', 'label' => 'CAPTCHA', 'required' => true, 'provider' => 'recaptcha'],
    ];

    /** @var array<int, array{url: string, body: mixed}> */
    private array $asked = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        update_option('fabricator_forms_recaptcha_site_key', 'site-key');
        update_option('fabricator_forms_recaptcha_secret_key', 'secret-key');
        $this->asked = [];
        // siteverify outcomes are remembered per request; each test is a request of its own.
        (new \ReflectionProperty(CaptchaField::class, 'verified'))->setValue(null, []);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function tear_down(): void
    {
        remove_all_filters('pre_http_request');
        parent::tear_down();
    }

    public function testATokenGoogleAcceptsForThisSitePasses(): void
    {
        $this->siteverify(['success' => true, 'hostname' => 'example.org']);
        $form = $this->createForm(self::FIELDS, [self::notification()]);

        $r = $this->submit($form, ['name' => 'Ada', 'g-recaptcha-response' => 'token-abc']);

        self::assertTrue($r['success'], wp_json_encode($r));
        self::assertCount(1, $this->asked, 'asked once');
        self::assertSame('https://www.google.com/recaptcha/api/siteverify', $this->asked[0]['url']);
        self::assertSame(['secret' => 'secret-key', 'response' => 'token-abc'], $this->asked[0]['body'], 'no visitor address sent along');
        self::assertCount(1, self::sentMail());
    }

    public function testTwoCaptchaFieldsAskGoogleOnceForTheSingleUseToken(): void
    {
        $this->siteverify(['success' => true, 'hostname' => 'example.org'], ['success' => false, 'error-codes' => ['timeout-or-duplicate']]);
        $fields   = self::FIELDS;
        $fields[] = ['id' => 'cap2', 'type' => 'captcha', 'label' => 'CAPTCHA again', 'required' => true, 'provider' => 'recaptcha'];
        $form     = $this->createForm($fields, [self::notification()]);

        self::assertTrue($this->submit($form, ['name' => 'Ada', 'g-recaptcha-response' => 'token-abc'])['success']);
        self::assertCount(1, $this->asked);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>|\WP_Error, 1: string}>
     */
    public static function refusals(): iterable
    {
        yield 'refused by Google' => [['success' => false, 'error-codes' => ['invalid-input-response']], 'Please confirm the CAPTCHA.'];
        yield 'minted on another site with the same key' => [['success' => true, 'hostname' => 'evil.example.net'], 'Please confirm the CAPTCHA.'];
        yield 'siteverify unreachable' => [new \WP_Error('http_request_failed', 'cURL error 28'), 'CAPTCHA verification could not be completed. Please try again.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testASubmissionWhoseTokenDoesNotVerifyIsRefusedAtTheCaptcha(array|\WP_Error $answer, string $message): void
    {
        $this->siteverify($answer);
        $form = $this->createForm(self::FIELDS, [self::notification()]);

        $r = $this->submit($form, ['name' => 'Ada', 'g-recaptcha-response' => 'token-abc']);

        self::assertFalse($r['success']);
        self::assertSame($message, $r['data']['errors']['cap'] ?? null);
        self::assertSame([], self::sentMail());
    }

    public function testNoTokenIsRefusedWithoutAskingGoogle(): void
    {
        $this->siteverify(['success' => true, 'hostname' => 'example.org']);
        $form = $this->createForm(self::FIELDS, [self::notification()]);

        $r = $this->submit($form, ['name' => 'Ada']);

        self::assertFalse($r['success']);
        self::assertArrayHasKey('cap', $r['data']['errors']);
        self::assertSame([], $this->asked);
    }

    public function testAnotherInvalidFieldIsNamedWhileTheCaptchaPasses(): void
    {
        // The visitor solved the CAPTCHA but left a required field empty: that field is the error, not the CAPTCHA.
        $this->siteverify(['success' => true, 'hostname' => 'example.org']);
        $form = $this->createForm(self::FIELDS, [self::notification()]);

        $r = $this->submit($form, ['name' => '', 'g-recaptcha-response' => 'token-abc']);

        self::assertFalse($r['success']);
        self::assertSame(['name'], array_keys($r['data']['errors']));
    }

    /**
     * Answers siteverify with $answers in turn (the last one repeats), recording what was asked.
     *
     * @param array<string, mixed>|\WP_Error ...$answers
     */
    private function siteverify(array|\WP_Error ...$answers): void
    {
        add_filter('pre_http_request', function ($pre, array $args, string $url) use ($answers) {
            if (!str_contains($url, 'recaptcha/api/siteverify')) {
                return $pre;
            }
            $this->asked[] = ['url' => $url, 'body' => $args['body'] ?? null];
            $answer = $answers[min(count($this->asked), count($answers)) - 1];
            return $answer instanceof \WP_Error
                ? $answer
                : ['headers' => [], 'body' => (string) wp_json_encode($answer), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        }, 10, 3);
    }
}
