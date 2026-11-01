<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Extension\LibraryChange;
use NightWorksIO\MutationGate\Extension\LibraryTest;
use NightWorksIO\MutationGate\Extension\RunnerLibrary;

it('names each change\'s mutator as the runner spells it', function (): void {
    $library = RunnerLibrary::spelling('A', 'B', 'C', 'D', 'E');

    expect(array_map(static fn(LibraryChange $change): string => $library->mutatorOf($change), LibraryChange::cases()))
        ->toBe(['A', 'B', 'C', 'D', 'E'])
        ->and([...$library->mutators(LibraryChange::Large, LibraryChange::Held, LibraryChange::Adds)])->toBe(['B', 'E', 'A']);
});

it('names each test file with the ending its runner gives test files, PHPUnit\'s where it is told none', function (): void {
    expect(RunnerLibrary::spelling('A', 'B', 'C', 'D', 'E')->testFile(LibraryTest::Money)->value())->toBe('tests/MoneyTest.php')
        ->and(RunnerLibrary::spelling('A', 'B', 'C', 'D', 'E', 'Spec.php')->testFile(LibraryTest::Double)->value())
        ->toBe('tests/DoubleSpec.php');
});

it('says whether its runner reports how long each mutant took', function (): void {
    $library = RunnerLibrary::spelling('A', 'B', 'C', 'D', 'E', 'Spec.php');

    expect($library->measures())->toBeTrue()
        ->and($library->unmeasured()->measures())->toBeFalse()
        ->and($library->unmeasured()->testFile(LibraryTest::Held)->value())->toBe('tests/HeldSpec.php')
        ->and($library->unmeasured()->mutatorOf(LibraryChange::Drains))->toBe('D');
});
