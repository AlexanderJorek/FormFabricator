<?php

namespace FabricatorForms\Tests\Integration\Form;

use FabricatorForms\Tests\Integration\AjaxTestCase;
use FabricatorForms\Tests\Support\Reflect;
use FabricatorForms\Utils\Altcha;

/**
 * A submission with an ALTCHA CAPTCHA field (Utils\Altcha): a challenge from the plugin's endpoint, solved as the widget
 * solves it, lets the submission through once; a reused, changed, expired or made-up answer is refused at the CAPTCHA.
 * That ALTCHA's own solver and verifier agree with Utils\Altcha is checked in the JS suite (tests/js/altcha.test.js).
 */
final class AltchaTest extends AjaxTestCase
{
    private const FIELDS = [
        ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
        ['id' => 'cap', 'type' => 'captcha', 'label' => 'CAPTCHA', 'required' => true, 'provider' => 'altcha'],
    ];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        // A few counters to try instead of thousands: the test solves each challenge in PHP.
        add_filter('fabricator_altcha_difficulty', static fn(): int => 20);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function tear_down(): void
    {
        remove_all_filters('fabricator_altcha_difficulty');
        parent::tear_down();
    }

    public function testTheEndpointHandsAnyVisitorASignedChallenge(): void
    {
        self::assertNotFalse(has_action('wp_ajax_nopriv_fabricator_altcha_challenge'), 'visitors who are not logged in');

        $challenge = $this->fetchChallenge();

        self::assertSame(['parameters', 'signature'], array_keys($challenge));
        self::assertSame('PBKDF2/SHA-256', $challenge['parameters']['algorithm']);
        self::assertGreaterThan(time(), $challenge['parameters']['expiresAt']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $challenge['signature']);
        self::assertNotSame($challenge['parameters']['nonce'], $this->fetchChallenge()['parameters']['nonce'], 'a new one each time');
    }

    public function testASolvedChallengeLetsTheSubmissionThroughOnce(): void
    {
        $form    = $this->createForm(self::FIELDS, [self::notification()]);
        $payload = self::payload($this->fetchChallenge());

        $r = $this->submit($form, ['name' => 'Ada', 'cap' => $payload]);
        self::assertTrue($r['success'], (string) wp_json_encode($r));
        self::assertCount(1, self::sentMail());

        $again = $this->submit($form, ['name' => 'Ada', 'cap' => $payload]);
        self::assertFalse($again['success'], 'the same answer a second time');
        self::assertSame('Please confirm the CAPTCHA.', $again['data']['errors']['cap'] ?? null);
        self::assertCount(1, self::sentMail());
    }

    public function testAnotherInvalidFieldLeavesTheAnswerUnused(): void
    {
        // CAPTCHA fields are checked last: a submission refused elsewhere must not use up the visitor's answer.
        $form    = $this->createForm(self::FIELDS, [self::notification()]);
        $payload = self::payload($this->fetchChallenge());

        $r = $this->submit($form, ['name' => '', 'cap' => $payload]);
        self::assertSame(['name'], array_keys($r['data']['errors']));

        self::assertTrue($this->submit($form, ['name' => 'Ada', 'cap' => $payload])['success']);
    }

    /**
     * @return iterable<string, array{0: callable(array): string}>
     */
    public static function refusedAnswers(): iterable
    {
        yield 'a parameter changed' => [static function (array $c): string {
            $c['parameters']['expiresAt'] += 3600;
            return self::payload($c);
        }];
        yield 'another key' => [static fn(array $c): string => self::payload($c, str_repeat('ab', 32))];
        yield 'expired, signed by this site' => [static function (array $c): string {
            $c['parameters']['expiresAt'] = time() - 1;
            return self::payload(self::resigned($c));
        }];
        yield 'another algorithm, signed by this site' => [static function (array $c): string {
            $c['parameters']['algorithm'] = 'SHA-256';
            return self::payload(self::resigned($c));
        }];
        yield 'signed with another secret' => [static function (array $c): string {
            $c['signature'] = hash_hmac('sha256', Reflect::call(Altcha::class, 'canonical', $c['parameters']), 'guess');
            return self::payload($c);
        }];
        yield 'not base64' => [static fn(): string => 'not a payload!'];
        yield 'not JSON' => [static fn(): string => base64_encode('{"challenge":')];
        yield 'too long' => [static fn(array $c): string => base64_encode((string) wp_json_encode(
            json_decode((string) base64_decode(self::payload($c), true), true) + ['padding' => str_repeat('x', 9000)]
        ))];
        yield 'empty' => [static fn(): string => ''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedAnswers')]
    public function testAnAnswerThatDoesNotSolveAChallengeOfThisSiteIsRefused(callable $answer): void
    {
        $form = $this->createForm(self::FIELDS, [self::notification()]);

        $r = $this->submit($form, ['name' => 'Ada', 'cap' => $answer($this->fetchChallenge())]);

        self::assertFalse($r['success']);
        self::assertSame('Please confirm the CAPTCHA.', $r['data']['errors']['cap'] ?? null);
        self::assertSame([], self::sentMail());
    }

    public function testOnANetworkAChallengeSolvedForOneSiteDoesNotPassOnAnother(): void
    {
        if (!is_multisite()) {
            self::markTestSkipped('Needs a network: run with WP_MULTISITE=1.');
        }
        $payload = self::payload($this->fetchChallenge());
        $other   = self::factory()->blog->create();

        switch_to_blog($other);
        try {
            self::assertFalse(Altcha::verify($payload), 'another site of the network');
        } finally {
            restore_current_blog();
        }
        self::assertTrue(Altcha::verify($payload), 'the site it was made for');
    }

    public function testAReCaptchaTokenDoesNotPassAnAltchaField(): void
    {
        $form = $this->createForm(self::FIELDS, [self::notification()]);

        $r = $this->submit($form, ['name' => 'Ada', 'g-recaptcha-response' => 'token-abc']);

        self::assertFalse($r['success']);
        self::assertArrayHasKey('cap', $r['data']['errors']);
    }

    public function testTheFieldShowsTheWidgetAndLoadsItsScriptFromThisSite(): void
    {
        $html = \FabricatorForms\Form\FormRenderer::render(0, [], [self::FIELDS[1]]);

        self::assertStringContainsString('<altcha-widget', $html);
        self::assertStringContainsString('action=fabricator_altcha_challenge', $html);
        self::assertStringNotContainsString('fabricator-captcha-gate', $html, 'no reCAPTCHA');
        ob_start();
        wp_script_modules()->print_enqueued_script_modules();
        $modules = (string) ob_get_clean();
        self::assertStringContainsString(FABRICATOR_FORMS_URL . 'vendor/altcha/altcha.min.js', $modules);
    }

    /**
     * A challenge from the plugin's endpoint, as the widget fetches it.
     *
     * @return array{parameters: array<string, mixed>, signature: string}
     */
    private function fetchChallenge(): array
    {
        $response  = $this->ajax('fabricator_altcha_challenge');
        $challenge = json_decode(trim($response['raw']), true);
        self::assertIsArray($challenge, $response['raw']);
        return $challenge;
    }

    /**
     * The widget's payload for $challenge: the counter whose key starts with the prefix, found by trying each in turn as
     * the widget does, and that key (or $key instead).
     *
     * @param array{parameters: array<string, mixed>, signature: string} $challenge
     */
    private static function payload(array $challenge, ?string $key = null): string
    {
        $p      = $challenge['parameters'];
        $prefix = (string) hex2bin($p['keyPrefix']);
        for ($counter = 0; $counter <= 1000; $counter++) {
            $derived = hash_pbkdf2('sha256', hex2bin($p['nonce']) . pack('N', $counter), (string) hex2bin($p['salt']), (int) $p['cost'], (int) $p['keyLength'], true);
            if (str_starts_with($derived, $prefix)) {
                break;
            }
        }
        $solution = ['counter' => $counter, 'derivedKey' => $key ?? bin2hex($derived), 'time' => 3.2];
        return base64_encode((string) wp_json_encode(['challenge' => $challenge, 'solution' => $solution], JSON_UNESCAPED_SLASHES));
    }

    /**
     * $challenge with its parameters signed again with this site's secret, as only the site itself could.
     *
     * @param array{parameters: array<string, mixed>, signature: string} $challenge
     * @return array{parameters: array<string, mixed>, signature: string}
     */
    private static function resigned(array $challenge): array
    {
        $secret                 = Reflect::call(Altcha::class, 'secret', 'challenge');
        $challenge['signature'] = hash_hmac('sha256', Reflect::call(Altcha::class, 'canonical', $challenge['parameters']), $secret);
        return $challenge;
    }
}
