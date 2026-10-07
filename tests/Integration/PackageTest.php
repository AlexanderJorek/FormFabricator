<?php

namespace FabricatorForms\Tests\Integration;

use FabricatorForms\Tests\Integration\Support\Package;
use PHPUnit\Framework\Attributes\Group;

/**
 * With FABRICATOR_TESTS_PACKAGE set (the release build sets it), the "package" group must really run against the
 * release zip: the plugin, its classes and its libraries all from the unpacked package, none from the repository.
 */
#[Group('package')]
final class PackageTest extends TestCase
{
    public function testThePluginAndItsLibrariesComeFromThePackage(): void
    {
        if (!Package::inUse()) {
            self::markTestSkipped('No release zip named (FABRICATOR_TESTS_PACKAGE); the suite runs against the repository.');
        }
        $package = wp_normalize_path(FABRICATOR_FORMS_PATH);
        $root    = wp_normalize_path(dirname(__DIR__, 2)) . '/';
        self::assertStringNotContainsString($root, $package, 'the plugin path is the unpacked package');
        foreach ([\FabricatorForms\Plugin::class, \FabricatorForms\PDF\Generator::class, \Mpdf\Mpdf::class, \Smalot\PdfParser\Parser::class] as $class) {
            $file = wp_normalize_path((string) (new \ReflectionClass($class))->getFileName());
            self::assertStringStartsWith($package, $file, $class . ' is loaded from the package');
        }
        self::assertFileDoesNotExist($package . 'tests', 'the package carries no tests');
    }
}
