<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

$mutant = static fn(string $native): Mutant => Mutant::of(
    MutantId::hash(Path::of('src/Money.php'), 'LessThan', $native, 0),
    $native,
    Location::of(Path::of('src/Money.php'), Line::of(1), Line::of(1)),
    Mutation::of('LessThan', MutatorFamily::Boundary, ''),
    MutantStatus::Killed,
    Unmeasured::duration(),
);
$natives = static fn(Mutants $mutants): array => array_map(static fn(Mutant $mutant): string => $mutant->nativeId(), iterator_to_array($mutants, preserve_keys: true));

it('holds nothing to begin with', function (): void {
    expect(Mutants::none())->toHaveCount(0);
});

it('keeps mutants in the order they were reported, numbered from nought', function () use ($mutant, $natives): void {
    expect($natives(Mutants::of(...['b' => $mutant('b'), 'a' => $mutant('a')])))->toBe(['b', 'a']);
});

it('adds a mutant without changing the mutants it came from', function () use ($mutant, $natives): void {
    $mutants = Mutants::of($mutant('a'));

    expect($natives($mutants->with($mutant('b'))))->toBe(['a', 'b'])
        ->and($mutants)->toHaveCount(1);
});

it('names the mutants two runs of the same code disagree on: a status that differs, or a mutant one lacks', function (): void {
    $of = static fn(string $diff, MutantStatus $status): Mutant => Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'LessThan', $diff, 0),
        $diff,
        Location::of(Path::of('src/Money.php'), Line::of(1), Line::of(1)),
        Mutation::of('LessThan', MutatorFamily::Boundary, $diff),
        $status,
        Unmeasured::duration(),
    );
    $here = Mutants::of($of("-a\n+b", MutantStatus::Killed), $of("-c\n+d", MutantStatus::Survived), $of("-e\n+f", MutantStatus::Killed));
    $there = Mutants::of($of("-a\n+b", MutantStatus::Killed), $of("-c\n+d", MutantStatus::Killed), $of("-g\n+h", MutantStatus::Killed));

    expect([...$here->disagreeingWith($there)])->toEqual([
        $of("-c\n+d", MutantStatus::Killed)->id(),
        $of("-e\n+f", MutantStatus::Killed)->id(),
        $of("-g\n+h", MutantStatus::Killed)->id(),
    ])
        ->and($here->disagreeingWith($here))->toHaveCount(0);
});
