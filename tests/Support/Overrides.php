<?php

namespace FabricatorForms\Tests\Support;

/**
 * State for the built-in overrides in namespace-overrides.php. null (or empty) means "use the real function".
 */
final class Overrides
{
    /** @var string[]|null Paths is_uploaded_file() accepts, as if PHP had received them in this request. */
    public static ?array $uploadedFiles = null;

    /** @var string[] Functions function_exists() denies, as on a PHP built without their extension. */
    public static array $missingFunctions = [];

    /**
     * @var array<int, array{0: callable, 1: array<int, mixed>}>|null Shutdown functions held back, with their
     *      arguments, for the test to run with runShutdownFunctions(); null registers them with PHP as usual.
     */
    public static ?array $shutdownFunctions = null;

    public static function isUploadedFile(string $filename): bool
    {
        return self::$uploadedFiles !== null
            ? in_array($filename, self::$uploadedFiles, true)
            : \is_uploaded_file($filename);
    }

    public static function functionExists(string $function): bool
    {
        return !in_array(strtolower($function), array_map('strtolower', self::$missingFunctions), true)
            && \function_exists($function);
    }

    public static function registerShutdownFunction(callable $callback, mixed ...$args): void
    {
        if (self::$shutdownFunctions === null) {
            \register_shutdown_function($callback, ...$args);
            return;
        }
        self::$shutdownFunctions[] = [$callback, $args];
    }

    /**
     * Runs the shutdown functions held back so far, in the order they were registered, as PHP would at the end of the
     * request, and holds back any registered later again.
     */
    public static function runShutdownFunctions(): void
    {
        $held = self::$shutdownFunctions ?? [];
        self::$shutdownFunctions = [];
        foreach ($held as [$callback, $args]) {
            $callback(...$args);
        }
    }

    /**
     * Back to the real functions. Shutdown functions a test held back and did not run go to PHP, which runs them at the
     * end of the process, so nothing they would remove outlives the run.
     */
    public static function reset(): void
    {
        $held = self::$shutdownFunctions ?? [];
        self::$uploadedFiles     = null;
        self::$missingFunctions  = [];
        self::$shutdownFunctions = null;
        foreach ($held as [$callback, $args]) {
            \register_shutdown_function($callback, ...$args);
        }
    }
}
