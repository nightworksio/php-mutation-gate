<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Recheck\Recheck;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Report\RecheckedText;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Rechecks;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('says how many still survive, how many were killed and how many are gone, then each in the order taken', function (): void {
    $survivor = Verdicts::survivor()->mutant();

    expect(RecheckedText::lines(Rechecks::mixed()))->toBe([
        'Survivors re-checked: 1 of 3 still survives. 1 killed now. 1 gone: the run made no mutant with their id again.',
        sprintf('  still survives: src/Money.php:7, LessToLessOrEqual, %s', $survivor->id()->value()),
        sprintf('  now killed: src/Price.php:9, Plus, %s', Rechecks::judged('src/Price.php:9', MutantJudgement::Killed)->mutant()->id()->value()),
        sprintf('  gone: src/Cart.php:4, Plus, %s', Rechecks::judged('src/Cart.php:4', MutantJudgement::Survived)->mutant()->id()->value()),
    ]);
});

it('counts many survivors in the plural, and leaves out a count of none', function (): void {
    $first = Rechecks::judged('src/Money.php:1', MutantJudgement::Survived);
    $second = Rechecks::judged('src/Money.php:2', MutantJudgement::Uncovered);

    expect(RecheckedText::headline(Rechecked::of(Uncovered::Count, Recheck::found($first, $first), Recheck::found($second, $second))))
        ->toBe('Survivors re-checked: 2 of 2 still survive.')
        ->and(RecheckedText::headline(Rechecked::of(Uncovered::Count, Recheck::found($first, Rechecks::judged('src/Money.php:1', MutantJudgement::Killed)))))
        ->toBe('Survivors re-checked: 0 of 1 still survive. 1 killed now.');
});

it('writes a place a ledger holds as one plain line that starts no CI command', function (): void {
    $at = static fn(string $file): JudgedMutant => JudgedMutant::of(Mutant::of(
        MutantId::hash(Path::of($file), 'Plus', '', 3),
        'native-3',
        Location::of(Path::of($file), Line::of(3), Line::of(3)),
        Mutation::of("Acme\\Plus\e[31m", MutatorFamily::None, ''),
        MutantStatus::Survived,
        Unmeasured::duration(),
    ), MutantJudgement::Survived);
    $line = static fn(string $file): string => RecheckedText::lines(Rechecked::of(Uncovered::Count, Recheck::gone($at($file))))[1];

    expect($line("src/a\n::error::b.php"))->toStartWith('  gone: src/a ::error::b.php:3, Plus[31m, ')
        ->and($line('src/##[group]x.php'))->toStartWith('  gone: src/## [group]x.php:3, ')
        ->and($line("src/\u{202E}evil.php"))->toStartWith('  gone: src/evil.php:3, ');
});
