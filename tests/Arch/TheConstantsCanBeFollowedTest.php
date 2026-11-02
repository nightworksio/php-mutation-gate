<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Php\Hierarchy;
use NightWorksIO\MutationGate\Core\Php\Reads;
use NightWorksIO\MutationGate\Core\Php\Source;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// ADR-0004 decision 8: a read the scan cannot follow makes every class
// constant's reads ambiguous, and its mutants unjudged in a file the fallback
// cannot judge.

/** The trees the gate's own run scans for the reads of a value. */
const FOLLOWED = ['src', 'plugins', 'tests'];

/** The scan's own fixtures, whose unfollowable reads it tests in strings and files it never scans as the project's. */
const UNSCANNED = '#^tests/(Unit/Core/Php/|.*/[Ff]ixtures?/)#';

it('leaves every class constant\'s reads followable in each file the gate\'s own run scans', function (): void {
    $probe = Symbol::constant('NightWorksIO\MutationGate\Probe\Anything', 'ANY');
    $files = array_filter(
        array_merge(...array_map(static fn(string $tree): array => Tree::filesUnder($tree), FOLLOWED)),
        static fn(string $file): bool => preg_match(UNSCANNED, $file) !== 1,
    );
    $unfollowable = [];

    foreach ($files as $file) {
        $source = Source::read(Path::of($file), Contents::of((string) file_get_contents(Tree::at($file))), str_starts_with($file, 'tests/'));
        $ambiguous = Reads::mayHold($probe, $source) && Reads::of($probe, $source, Hierarchy::of($source))->isAmbiguous();
        $unfollowable = $ambiguous ? [...$unfollowable, $file] : $unfollowable;
    }

    expect($unfollowable)->toBe([], sprintf(
        "These files read a class constant the scan cannot follow, such as by a `constant()` of a name not written out:\n  %s\n\nRead it through a `ReflectionClassConstant` instead.",
        implode("\n  ", $unfollowable),
    ));
});
