<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Report\TriageText;
use NightWorksIO\MutationGate\Core\Triage\Repeated;
use NightWorksIO\MutationGate\Tests\Support\Varying;

it('writes each varied mutant with where it is, its mutator, its id, what each run gave it, and how to reproduce it', function (): void {
    $flaky = Varying::mutant(7, MutantStatus::Killed, 'MoneyTest::adds');
    $id = $flaky->id()->value();
    $other = Varying::mutant(9, MutantStatus::Survived)->id()->value();

    $text = TriageText::of(Repeated::of(
        Mutants::of($flaky, Varying::mutant(9, MutantStatus::Survived)),
        Mutants::of(Varying::mutant(7, MutantStatus::Survived), Varying::mutant(9, MutantStatus::Survived)),
        Mutants::of(Varying::mutant(7, MutantStatus::Killed, 'MoneyTest::adds', 'MoneyTest::fits')),
    ));

    expect($text)->toBe(implode("\n", [
        sprintf('src/Money.php:7  IncrementInteger  %s', $id),
        '    killed in runs 1 and 3, by MoneyTest::adds, MoneyTest::fits',
        '    survived in run 2',
        sprintf('    Reproduce: vendor/bin/mutation-gate reproduce %s', $id),
        '',
        sprintf('src/Money.php:9  IncrementInteger  %s', $other),
        '    survived in runs 1 and 2',
        '    not made in run 3',
        sprintf('    Reproduce: vendor/bin/mutation-gate reproduce %s', $other),
        '',
        '2 of 2 mutants varied over 3 runs.',
    ]));
});

it('lists three or more runs with commas and a last and', function (): void {
    $survivor = Varying::mutant(7, MutantStatus::Survived);
    $killed = Varying::mutant(7, MutantStatus::Killed);

    expect(TriageText::of(Repeated::of(
        Mutants::of($survivor),
        Mutants::of($killed),
        Mutants::of($survivor),
        Mutants::of($killed),
        Mutants::of($survivor),
    )))->toContain("\n    survived in runs 1, 3 and 5\n    killed in runs 2 and 4\n");
});

it('says how many mutants the runs made, and that none varied, where every run gave each the same', function (): void {
    $runs = Mutants::of(Varying::mutant(7, MutantStatus::Survived), Varying::mutant(9, MutantStatus::Killed, 'A'));

    expect(TriageText::of(Repeated::of($runs, $runs, $runs)))->toBe('The 3 runs made 2 mutants, and none varied.');
});

it('counts one mutant as one', function (): void {
    $steady = Mutants::of(Varying::mutant(7, MutantStatus::Survived));
    $varying = Mutants::of(Varying::mutant(7, MutantStatus::Killed));

    expect(TriageText::of(Repeated::of($steady, $steady)))->toBe('The 2 runs made 1 mutant, and none varied.')
        ->and(TriageText::of(Repeated::of($steady, $varying)))->toEndWith("\n\n1 of 1 mutant varied over 2 runs.");
});

it('says none varied where the runs made no mutant', function (): void {
    expect(TriageText::of(Repeated::of(Mutants::none(), Mutants::none())))
        ->toBe('The runs made no mutant, so none varied.');
});
