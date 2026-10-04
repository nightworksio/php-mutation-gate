<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Triage\Outcomes;
use NightWorksIO\MutationGate\Core\Triage\Repeated;
use NightWorksIO\MutationGate\Tests\Support\Varying;

it('counts its runs, and each mutant any of them made once', function (): void {
    $repeated = Repeated::of(
        Mutants::of(Varying::mutant(1, MutantStatus::Killed), Varying::mutant(2, MutantStatus::Killed)),
        Mutants::of(Varying::mutant(1, MutantStatus::Killed), Varying::mutant(3, MutantStatus::Survived)),
    );

    expect([$repeated->runs(), $repeated->mutants()])->toBe([2, 3]);
});

it('finds no mutant varied that every run gave one status, whichever tests killed it', function (): void {
    $repeated = Repeated::of(
        Mutants::of(Varying::mutant(1, MutantStatus::Killed, 'A'), Varying::mutant(2, MutantStatus::Survived)),
        Mutants::of(Varying::mutant(1, MutantStatus::Killed, 'B'), Varying::mutant(2, MutantStatus::Survived)),
    );

    expect($repeated->varied())->toBe([]);
});

it('finds a mutant varied whose status differed, with the runs that gave each and the tests that killed it in them', function (): void {
    $varied = Repeated::of(
        Mutants::of(Varying::mutant(1, MutantStatus::Killed, 'A')),
        Mutants::of(Varying::mutant(1, MutantStatus::Survived)),
        Mutants::of(Varying::mutant(1, MutantStatus::Killed, 'B', 'A')),
    )->varied();

    expect(array_map(Varying::grouped(...), $varied))->toBe([[
        ['killed', [1, 3], ['A', 'B']],
        ['survived', [2], []],
    ]]);
});

it('finds a mutant varied that some run did not make', function (): void {
    $varied = Repeated::of(
        Mutants::of(Varying::mutant(1, MutantStatus::Survived)),
        Mutants::none(),
        Mutants::of(Varying::mutant(1, MutantStatus::Survived)),
    )->varied();

    expect(array_map(Varying::grouped(...), $varied))->toBe([[
        ['survived', [1, 3], []],
        ['not made', [2], []],
    ]]);
});

it('lists the varied mutants in the order the runs first made them, each as the first run that made it gave it', function (): void {
    $varied = Repeated::of(
        Mutants::of(Varying::mutant(1, MutantStatus::Survived)),
        Mutants::of(Varying::mutant(2, MutantStatus::Killed, 'A'), Varying::mutant(1, MutantStatus::Killed, 'B')),
        Mutants::of(Varying::mutant(2, MutantStatus::Survived), Varying::mutant(1, MutantStatus::Killed, 'B')),
    )->varied();

    expect(array_map(static fn(Outcomes $one): array => [
        $one->mutant()->location()->start()->number(),
        $one->mutant()->status(),
        count($one->mutant()->killers()),
    ], $varied))->toBe([[1, MutantStatus::Survived, 0], [2, MutantStatus::Killed, 1]]);
});

it('takes a mutant every run gave one status as one that did not vary', function (): void {
    $steady = Outcomes::over(Varying::mutant(1, MutantStatus::TimedOut), [
        Varying::mutant(1, MutantStatus::TimedOut),
        Varying::mutant(1, MutantStatus::TimedOut),
    ]);

    expect([$steady->didVary(), Varying::grouped($steady)])->toBe([false, [['timed-out', [1, 2], []]]]);
});
