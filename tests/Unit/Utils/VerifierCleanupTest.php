<?php

namespace FabricatorForms\Tests\Unit\Utils;

use FabricatorForms\Tests\Support\FakeWordPress;
use FabricatorForms\Tests\Support\PdfFixtures;
use FabricatorForms\Tests\Support\TestCase;
use FabricatorForms\Utils\VerifierCleanup;

/**
 * Uploaded verification PDFs and the images taken from them leave the protected temp folder about 10 minutes after
 * their last use — on the next request, even with WP-Cron off — while a copy a batch is still waiting on is kept alive.
 * (TESTING.md §5, "leave the protected temp folder…".)
 */
final class VerifierCleanupTest extends TestCase
{
    private const DUE = 'fabricator_verifier_sweep_due';

    /**
     * Brain Monkey's Patchwork routes file:// through a stream wrapper whose touch() lands one second late on Windows
     * (touch($f, $t) leaves filemtime $t + 1), for the test's touch() calls and the plugin's alike. Not a plugin bug.
     */
    private const MTIME_SLACK = 1;

    private FakeWordPress $wp;
    private string $ver = '';
    private string $img = '';
    private int $now    = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wp             = FakeWordPress::install();
        $this->wp->userId     = 7;
        $this->wp->uploadsDir = PdfFixtures::tempDir('fabricator-uploads-');
        $this->ver            = $this->wp->uploadsDir . '/formfabricator/verfiles';
        $this->img            = $this->wp->uploadsDir . '/formfabricator/verimages';
        mkdir($this->ver, 0777, true);
        mkdir($this->img, 0777, true);
        $this->now = time();
    }

    protected function tearDown(): void
    {
        PdfFixtures::removeTree($this->wp->uploadsDir);
        parent::tearDown();
    }

    public function testNothingPendingCostsNoWriteAfterTheFirstRequest(): void
    {
        VerifierCleanup::maybeSweep();
        self::assertSame('0', get_option(self::DUE, null), 'the due option is created as 0');

        $before = $this->wp->options;
        VerifierCleanup::maybeSweep();
        self::assertSame($before, $this->wp->options, 'nothing due: nothing written');
    }

    public function testExpiredCopiesGoOnTheNextRequestAndFreshOnesStay(): void
    {
        $this->pending(['ta' => 'a.pdf', 'tb' => 'b.pdf']);
        $this->file("$this->ver/index.php", $this->now - 9999);
        $this->file("$this->ver/web.config", $this->now - 9999);
        $this->file("$this->img/i1.png", $this->now - 9999);

        $due = (int) get_option(self::DUE);
        self::assertEqualsWithDelta($this->now + 600, $due, 1, 'due 10 minutes after the upload');
        self::assertSame($due, $this->wp->cron[VerifierCleanup::HOOK], 'one event at the due time');

        VerifierCleanup::maybeSweep();
        self::assertFileExists("$this->img/i1.png", 'no sweep before it is due');

        touch("$this->ver/a.pdf", $this->now - 660);
        touch("$this->ver/b.pdf", $this->now - 60);
        update_option(self::DUE, $this->now - 1);
        VerifierCleanup::maybeSweep();

        self::assertFileDoesNotExist("$this->ver/a.pdf", 'unused for 11 minutes');
        self::assertFileDoesNotExist("$this->img/i1.png");
        self::assertFileExists("$this->ver/b.pdf", 'used a minute ago');
        self::assertFileExists("$this->ver/index.php", 'guard files stay');
        self::assertFileExists("$this->ver/web.config", 'guard files stay');
        $due = (int) get_option(self::DUE);
        self::assertEqualsWithDelta($this->now - 60 + 600, $due, self::MTIME_SLACK, 'due moves to the oldest remaining copy');
        self::assertSame($due, $this->wp->cron[VerifierCleanup::HOOK]);
    }

    public function testAWaitingBatchKeepsItsCopiesAliveAndDropsFinishedOnes(): void
    {
        $this->pending(['ta' => 'a.pdf', 'tb' => 'b.pdf']);
        touch("$this->ver/b.pdf", $this->now - 60);
        unlink("$this->ver/a.pdf"); // checked and discarded already

        VerifierCleanup::refreshPending();
        clearstatcache();
        self::assertEqualsWithDelta($this->now - 60, filemtime("$this->ver/b.pdf"), self::MTIME_SLACK, 'refreshing is throttled to once a minute');

        $this->backdateRefresh();
        VerifierCleanup::refreshPending();
        clearstatcache();
        self::assertGreaterThanOrEqual($this->now, filemtime("$this->ver/b.pdf"), 'the waiting copy is renewed');
        self::assertSame(['tb'], get_transient('fabricator_vpending_7')['tokens'], 'the finished copy is dropped');

        unlink("$this->ver/b.pdf");
        $this->backdateRefresh();
        VerifierCleanup::refreshPending();
        self::assertFalse(get_transient('fabricator_vpending_7'), 'an empty list is removed');
        VerifierCleanup::sweep();
        self::assertSame('0', get_option(self::DUE), 'nothing stored: nothing due');
    }

    public function testANewUploadMovesTheEventEarlierButNeverLater(): void
    {
        $this->wp->cron[VerifierCleanup::HOOK] = $this->now + 5000;
        VerifierCleanup::trackPending(['tc']);
        self::assertSame($this->now + 600, $this->wp->cron[VerifierCleanup::HOOK]);

        VerifierCleanup::trackPending(['td']);
        self::assertSame($this->now + 600, $this->wp->cron[VerifierCleanup::HOOK]);
    }

    public function testThePendingListIsChangedUnderTheLockAndTheLockIsReleased(): void
    {
        $this->pending(['ta' => 'a.pdf']);
        self::assertContains('INSERT IGNORE INTO wp_options (option_name, option_value, autoload) VALUES (%s, %s, \'no\')', $this->wp->wpdb->queries);
        self::assertSame([], array_filter(array_keys($this->wp->options), static fn($n) => str_starts_with($n, 'fabricator_lock_')));
    }

    /**
     * Uploads copies as the verifier does: a file per token, a transient naming it, then trackPending().
     *
     * @param array<string, string> $tokens Token => file name.
     */
    private function pending(array $tokens): void
    {
        foreach ($tokens as $token => $name) {
            $this->file("$this->ver/$name", $this->now);
            set_transient('fabricator_pdf_' . $token, ['path' => "$this->ver/$name", 'uid' => 7]);
        }
        VerifierCleanup::trackPending(array_keys($tokens));
    }

    private function file(string $path, int $mtime): void
    {
        file_put_contents($path, 'x');
        touch($path, $mtime);
    }

    private function backdateRefresh(): void
    {
        $list              = get_transient('fabricator_vpending_7');
        $list['refreshed'] = $this->now - 120;
        set_transient('fabricator_vpending_7', $list);
    }
}
