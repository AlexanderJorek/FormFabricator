<?php

namespace FabricatorForms\Tests\Support;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Base case for the unit and perf suites: sets up and tears down Brain Monkey around every test, so a WordPress
 * function stubbed in one test never leaks into the next.
 */
abstract class TestCase extends PhpUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Overrides::reset();
        Monkey\tearDown();
        parent::tearDown();
    }
}
