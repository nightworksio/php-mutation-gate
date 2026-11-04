<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Mutated;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedSurvivor;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\HeldChecks;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\DoomedBy;
use NightWorksIO\MutationGate\Core\Verdict\Undoomed;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Tests\Support\Flows;

/** What one group of a shard's units came to, named after one file. */
$group = static fn(string $file, bool $dooms = false): Mutated => new Mutated(
    MutationResult::of(Flows::mutantsOf('src/Money.php'), 2)
        ->withWarnings(Warnings::of(Warning::that(sprintf('Warned of %s.', $file))))
        ->withEvidence(Evidences::none()->with(MutantId::firstOf(Path::of($file), 'Plus', '-a +b'), Evidence::none()->withEnded(Ended::of(1, signalled: false, printed: 'boom')))),
    MutantIds::of(MutantId::firstOf(Path::of($file), 'Minus', '-a +b')),
    HeldChecks::none(),
    Units::of(Unit::file(Path::of($file))),
    SurvivorChecks::none()->leaving(UncheckedSurvivor::failed('No analyser.', Path::of($file))),
    $dooms ? Doomed::of(Path::of($file), MutantId::firstOf(Path::of($file), 'Plus', '-a +b'), Path::of('src'), Floor::whole(), DoomedBy::Tree) : Undoomed::run(),
);

it('joins another group\'s outcome after its own, a doom either reached standing', function () use ($group): void {
    $joined = $group('src/A.php')->and($group('src/B.php', dooms: true));
    $first = $group('src/A.php', dooms: true)->and($group('src/B.php', dooms: true));

    expect(count($joined->result->mutants()))->toBe(2 * count(Flows::mutantsOf('src/Money.php')))
        ->and($joined->result->skipped())->toBe(4)
        ->and(array_map(static fn(Warning $warning): string => $warning->text(), [...$joined->result->warnings()]))
        ->toBe(['Warned of src/A.php.', 'Warned of src/B.php.'])
        ->and(count($joined->result->evidence()))->toBe(2)
        ->and(count($joined->flaky))->toBe(2)
        ->and(array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$joined->unjudged]))->toBe(['src/A.php', 'src/B.php'])
        ->and(count([...$joined->checks]))->toBe(2)
        ->and($joined->doomed instanceof Doomed ? $joined->doomed->unit()->value() : $joined->doomed)->toBe('src/B.php')
        ->and($first->doomed instanceof Doomed ? $first->doomed->unit()->value() : $first->doomed)->toBe('src/A.php')
        ->and($group('src/A.php')->and($group('src/B.php'))->doomed)->toBeInstanceOf(Undoomed::class);
});

it('leaves more units unjudged, keeping all else', function () use ($group): void {
    $left = $group('src/A.php')->leaving(Units::of(Unit::file(Path::of('src/C.php'))));

    expect(array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$left->unjudged]))->toBe(['src/A.php', 'src/C.php'])
        ->and($left->result)->toEqual($group('src/A.php')->result)
        ->and($left->flaky)->toEqual($group('src/A.php')->flaky);
});
