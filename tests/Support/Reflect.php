<?php

namespace FabricatorForms\Tests\Support;

/**
 * Calls a private static method. Used where the code under test is a private step of a large class (the verifier's
 * scans) and extracting it only for testing would change production code without changing behaviour.
 */
final class Reflect
{
    public static function call(string $class, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($class, $method))->invoke(null, ...$args);
    }
}
