<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Api;
use NightWorksIO\MutationGate\Tests\Support\Layer;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// G2: every port has a hand-written fake in tests/Fakes and a contract suite
// in tests/Contract/<Port> that runs the same expectations against the fake and
// against every adapter of it, so a fake cannot say something the real thing
// does not.

it('proves every port against its fake and against every adapter', function (): void {
    $offenders = [];

    foreach (Api::classesUnder(Layer::Port->directory()) as $port) {
        // A port that is not an interface is A2's to refuse, and no class implements it.
        if (! $port->isInterface()) {
            continue;
        }

        $name = $port->getShortName();
        $contract = sprintf('tests/Contract/%1$s/%1$sTest.php', $name);
        $fakes = array_values(array_filter(
            Api::classesUnder('tests/Fakes'),
            static fn(ReflectionClass $fake): bool => $fake->implementsInterface($port->getName()),
        ));

        if ($fakes === []) {
            $offenders[] = sprintf('%s has no fake in tests/Fakes', $name);
        }

        if (! is_file(Tree::at($contract))) {
            $offenders[] = sprintf('%s has no contract suite at %s', $name, $contract);

            continue;
        }

        $suite = (string) file_get_contents(Tree::at($contract));

        foreach ([...$fakes, ...adaptersOf($port)] as $implementation) {
            if (! str_contains($suite, $implementation->getShortName())) {
                $offenders[] = sprintf('%s does not run against %s', $contract, $implementation->getName());
            }
        }
    }

    // G2
    expect($offenders)->toBe([], sprintf(
        "These ports are not proven against both sides:\n  %s\n\nA fake the contract suite does not hold to the port says whatever its author assumed (G2).",
        implode("\n  ", $offenders),
    ));
});

/**
 * Every class under src/Adapter that implements a port.
 *
 * @param  ReflectionClass<object>       $port
 * @return list<ReflectionClass<object>>
 */
function adaptersOf(ReflectionClass $port): array
{
    return array_values(array_filter(
        Api::classesUnder(Layer::Adapter->directory()),
        static fn(ReflectionClass $adapter): bool => $adapter->implementsInterface($port->getName()),
    ));
}
