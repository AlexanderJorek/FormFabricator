<?php

namespace FabricatorForms\Tests\Support;

/**
 * State for the built-in overrides in namespace-overrides.php. null means "use the real function".
 */
final class Overrides
{
    /** @var string[]|null Paths is_uploaded_file() accepts, as if PHP had received them in this request. */
    public static ?array $uploadedFiles = null;

    public static function reset(): void
    {
        self::$uploadedFiles = null;
    }
}
