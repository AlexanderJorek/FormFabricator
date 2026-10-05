<?php

/**
 * Test doubles for PHP built-ins that Brain Monkey cannot replace.
 *
 * An unqualified call inside namespace FabricatorForms\Form looks for FabricatorForms\Form\is_uploaded_file() before the
 * global built-in, so defining it here intercepts FormProcessor's call (and UploadField's, and the verifier's, in their
 * namespaces). It defers to the real function unless a test lists paths in Overrides::$uploadedFiles, and the unit and
 * integration base cases clear that list after every test. Loaded by both suites' bootstraps, before the plugin.
 */

namespace FabricatorForms\Form {

    use FabricatorForms\Tests\Support\Overrides;

    function is_uploaded_file(string $filename): bool
    {
        return Overrides::isUploadedFile($filename);
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
}
