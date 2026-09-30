<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Api;
use NightWorksIO\MutationGate\Tests\Support\Layer;
use NightWorksIO\MutationGate\Tests\Support\Source;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// D4, D7, H1, H2, H6 and W1, over every class the package ships: src's and
// each plugin's.

/**
 * Every class-like the package ships whose short name ends in one of these words.
 *
 * @param  list<string> $suffixes
 * @return list<string>
 */
function classesEndingIn(array $suffixes): array
{
    $found = [];

    foreach (Api::shippedClasses() as $class) {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($class->getShortName(), $suffix)) {
                $found[] = $class->getName();
            }
        }
    }

    return $found;
}

it('names a closed set as an enum rather than a class', function (): void {
    $offenders = array_values(array_filter(
        classesEndingIn(['Status', 'State', 'Type', 'Kind']),
        static fn(string $class): bool => ! enum_exists($class),
    ));

    // D4
    expect($offenders)->toBe([], sprintf(
        "These are named for a closed set and are not enums:\n  %s\n\nA set written as strings lets a typo compile and a match miss an arm. Make it an enum (D4).",
        implode("\n  ", $offenders),
    ));
});

it('seals every class, and keeps every value readonly', function (): void {
    $offenders = [];

    foreach (Api::shippedClasses() as $class) {
        if ($class->isInterface() || $class->isEnum() || $class->isTrait()) {
            continue;
        }

        if (! $class->isFinal()) {
            $offenders[] = sprintf('%s is not final', $class->getName());
        }

        $immutable = ! str_starts_with($class->getName(), sprintf('%s\\', Layer::ROOT)) || array_any(
            [Layer::Core, Layer::Attribute, Layer::Mutator, Layer::Config, Layer::Extension],
            static fn(Layer $layer): bool => $layer->holds($class->getName()),
        );

        if ($immutable && ! $class->isReadOnly() && ! $class->implementsInterface(Throwable::class)) {
            $offenders[] = sprintf('%s is not readonly', $class->getName());
        }
    }

    // D7
    expect($offenders)->toBe([], sprintf(
        "These classes are open or mutable:\n  %s\n\nNothing here is designed to be extended, and a value the core decides with never changes after it is built (D7).",
        implode("\n  ", $offenders),
    ));
});

it('names no class for being vague', function (): void {
    $offenders = classesEndingIn(['Manager', 'Helper', 'Util', 'Utils', 'Service', 'Data', 'Info']);

    // H1
    expect($offenders)->toBe([], sprintf(
        "These carry a suffix that permits anything:\n  %s\n\nName the class for the one thing it answers (H1).",
        implode("\n  ", $offenders),
    ));
});

it('names no type for being an interface or abstract', function (): void {
    $offenders = classesEndingIn(['Interface']);

    foreach (Api::shippedClasses() as $class) {
        if (str_starts_with($class->getShortName(), 'Abstract')) {
            $offenders[] = $class->getName();
        }
    }

    // H2
    expect($offenders)->toBe([], sprintf(
        "These are named for what kind of type they are:\n  %s\n\nName a type for what it does (H2).",
        implode("\n  ", $offenders),
    ));
});

it('names an exception for what happened', function (): void {
    $offenders = classesEndingIn(['Exception']);

    // H6
    expect($offenders)->toBe([], sprintf(
        "These are named for being an exception:\n  %s\n\nAt the catch site the name reads as a fact: TheSuiteFailed, not SuiteFailedException (H6).",
        implode("\n  ", $offenders),
    ));
});

it('declares one class per file, the one its path names', function (): void {
    $offenders = [];

    foreach (array_merge(...array_map(Tree::filesUnder(...), Tree::shipped())) as $path) {
        $declared = Source::at($path)->declares();

        if ($declared !== [Source::classAtPath($path)]) {
            $offenders[] = sprintf('%s declares %s', $path, $declared === [] ? 'nothing' : implode(', ', $declared));
        }
    }

    // W1
    expect($offenders)->toBe([], sprintf(
        "These files do not declare the one class their path names:\n  %s\n\nThe autoloader finds a class by its path, and a second class in a file is found only after the first (W1).",
        implode("\n  ", $offenders),
    ));
});
