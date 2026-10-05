<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\PDF\GuardedRawDataParser;
use FabricatorForms\Tests\Support\TestCase;
use Smalot\PdfParser\RawData\RawDataParser;

/**
 * The decompression guard works by overriding pdfparser's protected internals. Composer allows any 2.x from 2.12 on: an
 * update that renamed one would leave the override uncalled and the guard silently off. This fails such an update in CI,
 * and the constructor refuses to parse at runtime (GuardedRawDataParser::assertGuardedMethodsExist()).
 */
final class GuardedParserHooksTest extends TestCase
{
    public function testEveryGuardedMethodIsPdfparsersOwnAndOverriddenHere(): void
    {
        foreach (GuardedRawDataParser::GUARDED_METHODS as $method) {
            self::assertTrue(method_exists(RawDataParser::class, $method), "pdfparser still has $method()");
            $parent = new \ReflectionMethod(RawDataParser::class, $method);
            self::assertTrue($parent->isProtected(), "$method() is protected in pdfparser");
            self::assertSame(
                GuardedRawDataParser::class,
                (new \ReflectionMethod(GuardedRawDataParser::class, $method))->getDeclaringClass()->getName(),
                "$method() is overridden by the guard"
            );
        }
    }
}
