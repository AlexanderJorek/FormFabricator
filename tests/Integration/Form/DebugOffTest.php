<?php

namespace FabricatorForms\Tests\Integration\Form;

use FabricatorForms\Admin\Verificationpage;
use FabricatorForms\Tests\Integration\AjaxTestCase;
use FabricatorForms\Tests\Support\Reflect;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * With WP_DEBUG off, a failed notification still reaches the PHP error log (TESTING.md §3), and so does an internal
 * failure of a PDF check, whose card says it is there. Runs in its own process with FABRICATOR_TESTS_WP_DEBUG=0.
 */
final class DebugOffTest extends AjaxTestCase
{
    public static function setUpBeforeClass(): void
    {
        putenv('FABRICATOR_TESTS_WP_DEBUG=0');
        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        putenv('FABRICATOR_TESTS_WP_DEBUG');
        parent::tearDownAfterClass();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAFailedNotificationIsLoggedWithWpDebugOff(): void
    {
        self::assertFalse(WP_DEBUG, 'this process runs as a live site does');
        $form = $this->createForm([['id' => 'name', 'type' => 'text', 'label' => 'Name']], [self::notification(['slug' => 'owner'])]);
        add_filter('pre_wp_mail', '__return_false');
        $log      = (string) tempnam(sys_get_temp_dir(), 'ff-log');
        $previous = ini_set('error_log', $log);

        $r = $this->submit($form, ['name' => 'Ada']);

        ini_set('error_log', (string) $previous);
        $logged = (string) file_get_contents($log);
        unlink($log);
        self::assertFalse($r['success']);
        self::assertStringContainsString('FabricatorForms MailSender: notification owner of form ' . $form . ' was not sent', $logged);
        self::assertStringNotContainsString('wp_mail to ', $logged, 'the WP_DEBUG-only lines stay out');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnInternalFailureOfAPdfCheckIsLoggedWithWpDebugOffAndAFileProblemIsNot(): void
    {
        self::assertFalse(WP_DEBUG, 'this process runs as a live site does');
        $log      = (string) tempnam(sys_get_temp_dir(), 'ff-log');
        $previous = ini_set('error_log', $log);

        $internal = Reflect::call(Verificationpage::class, 'failureMessage', new \LogicException('Unexpected state.'), 'Ada Lovelace order.pdf');
        $damaged  = Reflect::call(Verificationpage::class, 'failureMessage', new \RuntimeException('PDF structure unreadable.'), 'Ada Lovelace order.pdf');

        ini_set('error_log', (string) $previous);
        $logged = (string) file_get_contents($log);
        unlink($log);
        self::assertTrue($internal[1], 'an internal failure is an error');
        self::assertStringContainsString('PHP error log', $internal[0], 'its card says where the details are');
        self::assertStringContainsString('FabricatorForms verifier: internal error: LogicException: Unexpected state.', $logged);
        self::assertStringNotContainsString('Ada Lovelace', $logged, "the visitor's file name stays out of the log");

        self::assertFalse($damaged[1], "a damaged file is the file's problem, not an error");
        self::assertStringContainsString('damaged or incomplete', $damaged[0]);
        self::assertStringNotContainsString('PDF structure unreadable', $logged, 'and needs no log line to be understood');
    }
}
