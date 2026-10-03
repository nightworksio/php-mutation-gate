<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Choice;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Outcome;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/** Test files tests/T1.php to tests/Tn.php, from a first number. */
function choiceTests(int $count, int $from = 1): Paths
{
    $files = Paths::none();

    for ($each = $from; $each < $from + $count; $each++) {
        $files = $files->with(Path::of(sprintf('tests/T%d.php', $each)));
    }

    return $files;
}

$theme = Path::of('src/Theme.php');

it('runs the tests that read the value first, and then the rest of a small fallback', function () use ($theme): void {
    $choice = Choice::of(choiceTests(2), choiceTests(3, 2), ambiguous: false);

    expect($choice->first($theme))->toEqual(choiceTests(2))
        ->and($choice->then($theme))->toEqual(choiceTests(2, 3));
});

it('runs no fallback after the reads where it holds more than ten test files, or nothing more', function () use ($theme): void {
    expect(Choice::of(choiceTests(1), choiceTests(11), ambiguous: false)->then($theme))->toEqual(Paths::none())
        ->and(Choice::of(choiceTests(2), choiceTests(1), ambiguous: false)->then($theme))->toEqual(Paths::none())
        ->and(Choice::of(choiceTests(1), choiceTests(10), ambiguous: false)->then($theme))->toEqual(choiceTests(9, 2));
});

it('runs the tests that read an ambiguous value first, whatever the fallback holds, so that their kill stands', function () use ($theme): void {
    expect(Choice::of(choiceTests(1), choiceTests(10, 2), ambiguous: true)->first($theme))->toEqual(choiceTests(1))
        ->and(Choice::of(choiceTests(1), choiceTests(11), ambiguous: true)->first($theme))->toEqual(choiceTests(1));
});

it('runs a small fallback after ambiguous reads, and leaves unjudged a mutant they leave alive past ten', function () use ($theme): void {
    expect(Choice::of(choiceTests(1), choiceTests(10, 2), ambiguous: true)->then($theme))->toEqual(choiceTests(10, 2))
        ->and(Choice::of(choiceTests(1), choiceTests(11), ambiguous: true)->then($theme))
        ->toEqual(Outcome::unjudged('ambiguous reference; src/Theme.php is covered by 11 test files'));
});

it('runs a small fallback alone where an ambiguous scan found no read, and judges nothing past ten', function () use ($theme): void {
    $small = Choice::of(Paths::none(), choiceTests(10), ambiguous: true);

    expect($small->first($theme))->toEqual(choiceTests(10))
        ->and($small->then($theme))->toEqual(Paths::none())
        ->and(Choice::of(Paths::none(), choiceTests(11), ambiguous: true)->first($theme))
        ->toEqual(Outcome::unjudged('ambiguous reference; src/Theme.php is covered by 11 test files'));
});

it('judges nothing no test reads, and never runs no files, which Pest takes as the whole suite', function () use ($theme): void {
    expect(Choice::of(Paths::none(), choiceTests(3), ambiguous: false)->first($theme))
        ->toEqual(Outcome::unjudged('no test reaches this value'))
        ->and(Choice::of(Paths::none(), Paths::none(), ambiguous: true)->first($theme))
        ->toEqual(Outcome::unjudged('ambiguous reference; src/Theme.php is covered by 0 test files'))
        ->and(Choice::of(Paths::none(), choiceTests(3), ambiguous: false)->then($theme))->toEqual(Paths::none());
});
