<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Testo;

use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Attribute\Holds;
use NightWorksIO\MutationGate\Core\Native\Candidate;
use NightWorksIO\MutationGate\Core\Test\Group as GateGroup;
use ReflectionClass;
use ReflectionFunctionAbstract;
use Testo\Core\Context\TestInfo;
use Testo\Filter\Group;

/**
 * One of Testo's tests, as the gate names it: the address Testo gives it,
 * `Class::method` for a method, the id every runner's test has and Testo's
 * XML coverage says covers a line; the file its case is declared in; and the
 * groups its `#[Group]` and the holding groups its `#[Holds]` put it in, on
 * its function or on its class.
 */
final readonly class TestoTest
{
    public static function of(TestInfo $info): Candidate
    {
        $class = $info->caseInfo->definition->reflection;
        $declared = [$info->testDefinition->reflection, ...$class instanceof ReflectionClass ? [$class] : []];
        $groups = [];

        foreach ($declared as $reflected) {
            $groups = [...$groups, ...self::groupsOn($reflected)];
        }

        return Candidate::of($info->identity->fqn(), (string) $info->caseInfo->definition->file, ...$groups);
    }

    /**
     * The groups the `#[Group]`, which Testo allows once, and each `#[Holds]`
     * on a test's function or class put it in.
     *
     * @param ReflectionFunctionAbstract|ReflectionClass<object> $reflected
     *
     * @return list<GateGroup>
     */
    private static function groupsOn(ReflectionFunctionAbstract|ReflectionClass $reflected): array
    {
        $groups = [];

        foreach ($reflected->getAttributes(Group::class) as $group) {
            $groups = array_map(GateGroup::named(...), array_values($group->newInstance()->names));
        }

        foreach ($reflected->getAttributes(Holds::class) as $held) {
            $groups[] = GateGroup::holding($held->newInstance()->path());
        }

        return $groups;
    }
}
