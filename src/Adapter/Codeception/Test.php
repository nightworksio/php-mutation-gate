<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Codeception;

use function array_map;
use function basename;

use Codeception\Test\Cest;
use Codeception\Test\Test as CodeceptionTest;
use Codeception\Test\TestCaseWrapper;

use function is_string;

use NightWorksIO\MutationGate\Attribute\Holds;
use NightWorksIO\MutationGate\Core\Native\Candidate;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

use function sprintf;

/**
 * A Codeception test as the gate names it: a unit test or a Cest by its
 * class and its method, as `Class::method`, the id a test of any runner
 * has, with `#` and its data set's name or its example's index where it has
 * one; a Cept or a scenario by its file's name and its own, as
 * `file:name`. It is in each group Codeception puts it in, and in the
 * holding group of each `#[Holds]` on its class or its method.
 */
final readonly class Test
{
    /** How a test that is no method of a class is named: by its file's name and its own. */
    private const string NAMED = '%s:%s';

    public static function of(CodeceptionTest $test): Candidate
    {
        $metadata = $test->getMetadata();
        $class = match (true) {
            $test instanceof TestCaseWrapper => $test->getTestCase()::class,
            $test instanceof Cest => $test->getTestInstance()::class,
            default => '',
        };
        $groups = [];

        foreach ($metadata->getGroups() as $group) {
            $groups = is_string($group) ? [...$groups, Group::named($group)] : $groups;
        }

        if ($class === '') {
            $named = sprintf(self::NAMED, basename($test->getFileName()), $metadata->getName());

            return Candidate::of($named, $test->getFileName(), ...$groups);
        }

        $method = new ReflectionMethod($class, $metadata->getName());
        $index = $metadata->getIndex();
        $id = sprintf('%s%s%s', $class, TestMethod::SEPARATOR, $method->getName());
        $holds = [...new ReflectionClass($class)->getAttributes(Holds::class), ...$method->getAttributes(Holds::class)];

        return Candidate::of(
            $index === null ? $id : sprintf(TestMethod::ROW, $id, $index),
            $test->getFileName(),
            ...$groups,
            ...array_map(
                static fn(ReflectionAttribute $held): Group => Group::holding($held->newInstance()->path()),
                $holds,
            ),
        );
    }
}
