<?php

namespace FabricatorForms\Tests\Integration\Form;

use FabricatorForms\Tests\Integration\AjaxTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * With WP_DEBUG off, a failed notification still reaches the PHP error log (TESTING.md §3). Runs in its own process
 * with FABRICATOR_TESTS_WP_DEBUG=0.
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
}
