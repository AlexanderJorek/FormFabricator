<?php

namespace FabricatorForms\Tests\Unit\Form;

use Brain\Monkey\Functions;
use FabricatorForms\Fields\HtmlField;
use FabricatorForms\Form\MailSender;
use FabricatorForms\Tests\Support\Reflect;
use FabricatorForms\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Two guards against URLs that arrive after the author's HTML was sanitized:
 *
 * - MailSender::restrictLinkProtocols(): a visitor's answer filled into href="{field}" must not bring its own
 *   javascript: scheme into the email.
 * - HtmlField::stripRemoteResourcesForPdf(): mPDF fetches <img src> and CSS url() server-side, so only same-origin,
 *   relative or data: references may reach it (the SSRF cases in TESTING.md §4, HTML block — 1.0.7).
 *
 * wp_kses_bad_protocol() is stubbed with its scheme-stripping behaviour; HtmlSanitizer's <use> narrowing needs the
 * real wp_kses() and is covered in the integration suite.
 */
final class LinkSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_kses_bad_protocol')->alias(static function ($value, array $allowed): string {
            $value = trim((string) $value);
            for ($i = 0; $i < 6; $i++) {
                if (!preg_match('#^\s*([a-z0-9+.\-]+)\s*:#i', $value, $m) || in_array(strtolower($m[1]), array_map('strtolower', $allowed), true)) {
                    return $value;
                }
                $value = trim(substr($value, strlen($m[0])));
            }
            return $value;
        });
        Functions\when('home_url')->justReturn('https://own-host');
        Functions\when('wp_parse_url')->alias(static fn($u, $c = -1) => parse_url((string) $u, $c));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function keptLinks(): array
    {
        return [
            'https with entity'      => ['<a href="https://example.test/a?b=c&amp;d=e">ok</a>'],
            'http image'             => ['<img src="http://example.test/logo.png">'],
            'mailto'                 => ['<a href="mailto:someone@example.test">mail</a>'],
            'cid attachment'         => ['<img src="cid:attachment-1">'],
            'anchor'                 => ['<a href="#anchor">anchor</a>'],
            'relative'               => ['<a href="/relative/path">relative</a>'],
            'other quote style'      => ["<a href='https://example.test/q?x=\"quoted\"'>other quote style</a>"],
        ];
    }

    #[DataProvider('keptLinks')]
    public function testSafeLinksAreKeptUnchanged(string $html): void
    {
        self::assertSame($html, self::restrict($html));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function droppedLinks(): array
    {
        return [
            'javascript'             => ['<a href="javascript:alert(1)">x</a>'],
            'mixed case'             => ['<a href="JaVaScRiPt:alert(1)">x</a>'],
            'leading spaces'         => ['<a href="  javascript:alert(1)">x</a>'],
            'entity inside scheme'   => ['<a href="java&#115;cript:alert(1)">x</a>'],
            'vbscript image'         => ['<img src="vbscript:msgbox(1)">'],
            'single quotes'          => ["<a href='javascript:alert(1)'>x</a>"],
            'single inside double'   => ['<a href="javascript:alert(\'single inside double\')">x</a>'],
        ];
    }

    #[DataProvider('droppedLinks')]
    public function testDangerousSchemesAreDropped(string $html): void
    {
        $out = self::restrict($html);
        self::assertStringNotContainsStringIgnoringCase('javascript', $out);
        self::assertStringNotContainsStringIgnoringCase('vbscript', $out);
    }

    public function testAVisitorsAnswerInsideAnAuthorsLink(): void
    {
        $body = '<p>Hello <a href="{website}">your site</a></p>';
        self::assertSame('<p>Hello <a href="">your site</a></p>', self::restrict(str_replace('{website}', 'javascript:alert(document.cookie)', $body)));
        self::assertSame(
            '<p>Hello <a href="https://visitor.test/page">your site</a></p>',
            self::restrict(str_replace('{website}', 'https://visitor.test/page', $body))
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function imageSources(): array
    {
        return [
            'metadata IP, other quote inside' => ['http://169.254.169.254/latest/?x=\'', false],
            'entity-quoted remote'            => ['&quot;http://169.254.169.254/&quot;', false],
            'numeric-entity-quoted remote'    => ['&#34;http://169.254.169.254/&#34;', false],
            'apos-quoted remote'              => ['&apos;http://evil/x&apos;', false],
            'numeric apos remote'             => ['&#039;http://evil/x&#039;', false],
            'angle-bracketed remote'          => ['&lt;http://evil/x&gt;', false],
            'backtick-quoted remote'          => ['`http://evil/x`', false],
            'space in path'                   => ['x y.png', false],
            'tab before scheme'               => ["\thttp://evil/x", false],
            'traversal'                       => ['../secret.png', false],
            'encoded traversal'               => ['..%2Fsecret.png', false],
            'absolute path'                   => ['/wp-content/a.png', false],
            'javascript'                      => ['javascript:alert(1)', false],
            'remote host'                     => ['http://evil/x', false],
            'protocol-relative'               => ['//evil/x', false],
            'own host, other port'            => ['http://own-host:6379/', false],
            'own host'                        => ['https://own-host/logo.png', true],
            'relative file'                   => ['logo.png', true],
            'relative dir'                    => ['images/logo.png', true],
            'encoded space'                   => ['img/a%20b.png', true],
            'query with entity'               => ['a.php?x=1&amp;y=2', true],
            'nested relative'                 => ['sub/dir/pic-2.jpg', true],
            'fragment'                        => ['#frag', true],
            'data URI'                        => ['data:image/png;base64,AAA', true],
        ];
    }

    #[DataProvider('imageSources')]
    public function testOnlyLocalImageSourcesReachThePdf(string $src, bool $kept): void
    {
        $out = self::stripForPdf('<img src="' . $src . '">');
        $kept ? self::assertStringContainsString('src=', $out) : self::assertStringNotContainsString('src=', $out);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cssUrls(): array
    {
        return [
            'single-quoted'               => ["background:url('http://evil/y')", 'background:none'],
            'unquoted'                      => ['background:url(http://evil/z)', 'background:none'],
            'parenthesis inside (TESTING)'  => ['background:url(http://169.254.169.254/latest/x(y)', 'background:none'],
            'unterminated (TESTING)'        => ['background:url(http://169.254.169.254/latest/x', 'background:none'],
            'same-origin kept'              => ["background:url('https://own-host/bg.png')", "background:url('https://own-host/bg.png')"],
            'unquoted same-origin kept'     => ['background:url(https://own-host/c.png)', 'background:url(https://own-host/c.png)'],
            'one of two stripped'           => ["background:url(http://evil/a.png), url('https://own-host/d.png')", "background:none, url('https://own-host/d.png')"],
        ];
    }

    #[DataProvider('cssUrls')]
    public function testOnlyLocalCssUrlsReachThePdf(string $style, string $expected): void
    {
        $out = self::stripForPdf('<p style="' . $style . '">x</p>');
        self::assertMatchesRegularExpression('/\sstyle="([^"]*)"/', $out);
        preg_match('/\sstyle="([^"]*)"/', $out, $m);
        self::assertSame($expected, $m[1]);
    }

    public function testAnEntityQuotedUrlInsideAStyleAttributeIsStripped(): void
    {
        // TESTING.md's third SSRF case: wp_kses re-encodes the quotes, mPDF decodes them again before fetching.
        $out = self::stripForPdf('<p style="background:url(&quot;http://169.254.169.254/&quot;)">x</p>');
        self::assertStringNotContainsString('169.254', $out);

        // An apostrophe inside it belongs to the URL: the whole url(…) is replaced, with no quote debris left behind,
        // and the attribute keeps its single leading space.
        $out = self::stripForPdf('<p style="background:url(&quot;http://evil/x?a=\'&quot;)">x</p>');
        self::assertSame('<p style="background:none">x</p>', $out);
    }

    private static function restrict(string $html): string
    {
        return Reflect::call(MailSender::class, 'restrictLinkProtocols', $html);
    }

    private static function stripForPdf(string $html): string
    {
        return Reflect::call(HtmlField::class, 'stripRemoteResourcesForPdf', $html);
    }
}
