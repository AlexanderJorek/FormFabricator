<?php

/**
 * Test doubles for PHP built-ins that Brain Monkey cannot replace.
 *
 * An unqualified call inside namespace FabricatorForms\Form looks for FabricatorForms\Form\is_uploaded_file() before the
 * global built-in, so defining it here intercepts FormProcessor's call. It defers to the real function unless a test
 * lists paths in Overrides::$uploadedFiles, and Support\TestCase clears that list after every test.
 */

namespace FabricatorForms\Form;

use FabricatorForms\Tests\Support\Overrides;

function is_uploaded_file(string $filename): bool
{
    return Overrides::$uploadedFiles !== null
        ? in_array($filename, Overrides::$uploadedFiles, true)
        : \is_uploaded_file($filename);
}
