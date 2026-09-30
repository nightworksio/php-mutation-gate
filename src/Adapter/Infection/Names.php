<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_key_exists;
use function array_keys;

use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use NightWorksIO\MutationGate\Core\Test\TestNames;

/**
 * Each test as PHPUnit's JUnit log names it: the file that declares its
 * class, and its method's name, with the data set row where its id runs one.
 * Nothing runs: the class's file is found by its tokens.
 */
final readonly class Names
{
    public static function of(Project $project, TestIds $tests): TestNames
    {
        $classes = [];

        foreach ($tests as $test) {
            $classes[TestMethod::of($test)->className()] = true;
        }

        $files = TestFiles::byClass($project, array_keys($classes));
        $names = TestNames::none();

        foreach ($tests as $test) {
            $method = TestMethod::of($test);
            $names = $method->method() !== '' && array_key_exists($method->className(), $files)
                ? $names->with($test, $method->in($files[$method->className()], $method->method()))
                : $names;
        }

        return $names;
    }
}
