<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Pruning\PruningAccount;
use NightWorksIO\MutationGate\Core\Pruning\Survival;
use NightWorksIO\MutationGate\Core\Pruning\Window;
use NightWorksIO\MutationGate\Core\Report\PruningText;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Support\PruningCases;

/** What pruning one mutator in one unit did, carrying so many mutants from runs no older than so many seconds. */
function prunedOnce(int $carried, float $audit): PruningAccount
{
    $mutants = [];

    for ($line = 1; $line <= $carried; ++$line) {
        $mutants[] = PruningCases::mutant('src/A.php', 'Plus', $line);
    }

    return PruningAccount::of(
        Pruned::of(MutatorNames::of('Plus'), Paths::of(Path::of('src/A.php'))),
        Survival::none(),
        Name::of('pest'),
        Window::of(2),
        Seconds::of($audit),
        UnitResults::of(UnitResult::of(Unit::file(Path::of('src/A.php')), Origin::Run, Mutants::none())
            ->carryingPruned(Mutants::of(...$mutants), ProvedKills::none())),
    );
}

it('says how many mutators it pruned on how many units, and how many mutants it carried from how far back', function (): void {
    expect(PruningText::of(PruningCases::account()))
        ->toBe('Pruned: 2 mutators on 1 unit; 3 mutants carried from runs of the last 7 days.');
});

it('says one of each in the singular, a thousands separator in a count, and an audit of no whole days as a duration', function (int $carried, float $audit, string $line): void {
    expect(PruningText::of(prunedOnce($carried, $audit)))->toBe($line);
})->with([
    'one day' => [1, 86_400.0, 'Pruned: 1 mutator on 1 unit; 1 mutant carried from runs of the last day.'],
    'hours' => [1_001, 43_200.0, 'Pruned: 1 mutator on 1 unit; 1,001 mutants carried from runs of the last 12h.'],
    'a day and hours' => [2, 129_600.0, 'Pruned: 1 mutator on 1 unit; 2 mutants carried from runs of the last 36h.'],
    'days and hours' => [2, 216_000.0, 'Pruned: 1 mutator on 1 unit; 2 mutants carried from runs of the last 60h.'],
]);

it('says nothing where the run pruned nothing', function (): void {
    expect(PruningText::of(PruningAccount::none()))->toBe('');
});
