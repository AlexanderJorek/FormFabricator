<?php

namespace FabricatorForms\Tests\Unit\Utils;

use FabricatorForms\Tests\Support\TestCase;

/**
 * With WP_DEBUG on, log lines recorded visitors' file names, which often hold a person's name (GDPR Art. 5(1)(c)). They
 * now record a short hash and the extension only (fabricator_log_file()).
 */
final class LogFileNameTest extends TestCase
{
    public function testALogLineNamesAFileByHashAndExtensionOnly(): void
    {
        $logged = \FabricatorForms\fabricator_log_file('Lebenslauf Erika Mustermann.PDF');

        self::assertMatchesRegularExpression('/^file #[0-9a-f]{8}\.pdf$/', $logged);
        self::assertStringNotContainsString('Mustermann', $logged);
        self::assertSame($logged, \FabricatorForms\fabricator_log_file('Lebenslauf Erika Mustermann.PDF'), 'the same file, the same name in every line');
        self::assertNotSame($logged, \FabricatorForms\fabricator_log_file('Lebenslauf Max Mustermann.PDF'));
        // An "extension" that is no plain one is left out rather than logged.
        self::assertMatchesRegularExpression('/^file #[0-9a-f]{8}$/', \FabricatorForms\fabricator_log_file('notes.<script>'));
    }
}
