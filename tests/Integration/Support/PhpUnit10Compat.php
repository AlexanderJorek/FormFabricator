<?php

namespace FabricatorForms\Tests\Integration\Support;

/**
 * WordPress's test library targets PHPUnit 9: WP_UnitTestCase_Base::expectDeprecated() reads @expectedDeprecated
 * through PHPUnit\Util\Test::parseTestMethodAnnotations(), which PHPUnit 10 removed, so every test errors before it
 * starts. This suite never uses those annotations (it calls setExpectedDeprecated()/setExpectedIncorrectUsage()
 * where needed), so the override keeps the method's hook registration unchanged and drops only the annotation read.
 * Nothing else in the library that this suite reaches depends on PHPUnit 9.
 */
trait PhpUnit10Compat
{
    public function expectDeprecated()
    {
        add_action('deprecated_function_run', [$this, 'deprecated_function_run'], 10, 3);
        add_action('deprecated_argument_run', [$this, 'deprecated_function_run'], 10, 3);
        add_action('deprecated_class_run', [$this, 'deprecated_function_run'], 10, 3);
        add_action('deprecated_file_included', [$this, 'deprecated_function_run'], 10, 4);
        add_action('deprecated_hook_run', [$this, 'deprecated_function_run'], 10, 4);
        add_action('doing_it_wrong_run', [$this, 'doing_it_wrong_run'], 10, 3);

        add_action('deprecated_function_trigger_error', '__return_false');
        add_action('deprecated_argument_trigger_error', '__return_false');
        add_action('deprecated_class_trigger_error', '__return_false');
        add_action('deprecated_file_trigger_error', '__return_false');
        add_action('deprecated_hook_trigger_error', '__return_false');
        add_action('doing_it_wrong_trigger_error', '__return_false');
    }
}
