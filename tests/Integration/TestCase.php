<?php

namespace FabricatorForms\Tests\Integration;

use FabricatorForms\Tests\Integration\Support\PhpUnit10Compat;

/**
 * Base case for the integration suite: WP_UnitTestCase (a transaction per test, rolled back afterwards) made to run
 * on PHPUnit 10 — see Support\PhpUnit10Compat.
 */
abstract class TestCase extends \WP_UnitTestCase
{
    use PhpUnit10Compat;
}
