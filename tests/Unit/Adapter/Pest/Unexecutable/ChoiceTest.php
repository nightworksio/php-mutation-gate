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

it('runs the tests that read the value first, and then the rest of a small fallback', function (): void {
    $choice = Choice::of(choiceTests(2), choiceTests(3, 2), ambiguous: false);

    expect($choice->first(Path::of('src/Theme.php')))->toEqual(choiceTests(2))
        ->and($choice->then())->toEqual(choiceTests(2, 3));
});

it('runs no fallback after the reads where it holds more than ten test files, or nothing more', function (): void {
    expect(Choice::of(choiceTests(1), choiceTests(11), ambiguous: false)->then())->toEqual(Paths::none())
        ->and(Choice::of(choiceTests(2), choiceTests(1), ambiguous: false)->then())->toEqual(Paths::none())
        ->and(Choice::of(choiceTests(1), choiceTests(10), ambiguous: false)->then())->toEqual(choiceTests(9, 2));
});

it('runs a small fallback beside ambiguous reads, and judges nothing past ten', function (): void {
    $small = Choice::of(choiceTests(1), choiceTests(10, 2), ambiguous: true);
    $large = Choice::of(choiceTests(1), choiceTests(11), ambiguous: true);

    expect($small->first(Path::of('src/Theme.php')))->toEqual(choiceTests(11))
        ->and($small->then())->toEqual(Paths::none())
        ->and($large->first(Path::of('src/Theme.php')))
        ->toEqual(Outcome::unjudged('ambiguous reference; src/Theme.php is covered by 11 test files'));
});

it('judges nothing that no test reads', function (): void {
    expect(Choice::of(Paths::none(), choiceTests(3), ambiguous: false)->first(Path::of('src/Theme.php')))
        ->toEqual(Outcome::unjudged('no test reaches this value'));
});
