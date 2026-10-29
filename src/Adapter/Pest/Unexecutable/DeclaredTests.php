<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\ClosureCall;
use NightWorksIO\MutationGate\Core\Php\Source;

/**
 * The test files that declare a test of their own, as Pest's `it()`,
 * `test()` and `arch()` do: files Pest runs as test files, though their
 * tests may run no line a map names them by.
 */
final readonly class DeclaredTests
{
    /** Those of these sources that are test files declaring a test, by their paths. */
    public static function among(Source ...$sources): Paths
    {
        $declaring = Paths::none();

        foreach ($sources as $source) {
            $declares = $source->isTest() && ClosureCall::declaresTest($source->tokens());
            $declaring = $declares ? $declaring->with($source->path()) : $declaring;
        }

        return $declaring;
    }
}
