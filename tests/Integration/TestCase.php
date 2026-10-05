<?php

namespace FabricatorForms\Tests\Integration;

use FabricatorForms\Tests\Integration\Support\PhpUnit10Compat;
use FabricatorForms\Tests\Support\Overrides;

/**
 * Base case for the integration suite: WP_UnitTestCase (a transaction per test, rolled back afterwards) made to run
 * on PHPUnit 10 — see Support\PhpUnit10Compat.
 */
abstract class TestCase extends \WP_UnitTestCase
{
    use PhpUnit10Compat;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function tear_down(): void
    {
        Overrides::reset();
        parent::tear_down();
    }
}
