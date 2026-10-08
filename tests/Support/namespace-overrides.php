<?php

/**
 * Test doubles for PHP built-ins that Brain Monkey cannot replace.
 *
 * An unqualified call inside namespace FabricatorForms\Form looks for FabricatorForms\Form\is_uploaded_file() before the
 * global built-in, so defining it here intercepts FormProcessor's call (and UploadField's, and the verifier's, in their
 * namespaces). Each double defers to the real function unless a test sets its Overrides state, and the unit and
 * integration base cases reset that state after every test. Loaded by both suites' bootstraps, before the plugin.
 *
 * - is_uploaded_file():          accepts the paths in Overrides::$uploadedFiles.
 * - function_exists():           denies Overrides::$missingFunctions (a PHP without openssl, for the seal key settings
 *                                and HashSeal).
 * - register_shutdown_function(): held back for Overrides::runShutdownFunctions() (the end of a request, which a test
 *                                otherwise never reaches, for FormProcessor's and PrivateDir's clean-up).
 */

namespace FabricatorForms\Form {

    use FabricatorForms\Tests\Support\Overrides;

    function is_uploaded_file(string $filename): bool
    {
        return Overrides::isUploadedFile($filename);
    }

    function register_shutdown_function(callable $callback, mixed ...$args): void
    {
        Overrides::registerShutdownFunction($callback, ...$args);
    }
}

namespace FabricatorForms\Fields {

    use FabricatorForms\Tests\Support\Overrides;

    function is_uploaded_file(string $filename): bool
    {
        return Overrides::isUploadedFile($filename);
    }
}

namespace FabricatorForms\Admin {

    use FabricatorForms\Tests\Support\Overrides;

    function is_uploaded_file(string $filename): bool
    {
        return Overrides::isUploadedFile($filename);
    }

    function function_exists(string $function): bool
    {
        return Overrides::functionExists($function);
    }
}

namespace FabricatorForms\PDF {

    use FabricatorForms\Tests\Support\Overrides;

    function function_exists(string $function): bool
    {
        return Overrides::functionExists($function);
    }
}

namespace FabricatorForms\Utils {

    use FabricatorForms\Tests\Support\Overrides;

    function register_shutdown_function(callable $callback, mixed ...$args): void
    {
        Overrides::registerShutdownFunction($callback, ...$args);
    }
}
