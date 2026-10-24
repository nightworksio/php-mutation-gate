<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Php\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Php\Source;

/**
 * The test method a line of a test file is in, by the id PHPUnit gives it:
 * `<class>::<method>`, the class fully qualified by the file's namespace. A
 * line inside a closure is in the method around it; a line in no method, or
 * in a method of an anonymous class, is in none.
 */
final readonly class TestAt
{
    public static function in(Contents $file, Line $line): TestId|Nameless
    {
        $enclosing = Enclosing::in($file, $line);

        if ($enclosing instanceof Nameless) {
            return $enclosing;
        }

        $class = $enclosing->owner();

        return $class instanceof Nameless
            ? $class
            : TestMethod::id(Source::scopeOf($file)->declared($class), $enclosing->name());
    }
}
