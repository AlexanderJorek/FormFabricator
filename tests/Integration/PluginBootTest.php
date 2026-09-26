<?php

namespace FabricatorForms\Tests\Integration;

/**
 * The plugin loads into a real WordPress the way WordPress loads it, and registers what the rest of the suite uses.
 */
final class PluginBootTest extends TestCase
{
    public function testThePluginIsLoadedAndInitialised(): void
    {
        self::assertTrue(defined('FABRICATOR_FORMS_VERSION'));
        self::assertTrue(class_exists(\FabricatorForms\Plugin::class));
        self::assertNotEmpty(\FabricatorForms\Fields\FieldRegistry::all(), 'field types registered');
    }

    public function testTheSubmissionHandlerIsHooked(): void
    {
        self::assertNotFalse(has_action('fabricator_forms_submission'), 'MailSender listens for submissions');
    }
}
