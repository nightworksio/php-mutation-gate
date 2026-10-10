<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Pruning;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Pruning\Pruner;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\PruningCases;

/**
 * The files a pruner prunes in, and the mutators it prunes, over the A to E units of these proofs.
 *
 * @return list<list<string>>
 */
function prunedOver(Pruner $pruner, Proofs $proofs, bool|Absent $digested = new Absent()): array
{
    $units = Units::of(
        Unit::file(Path::of('src/A.php')),
        Unit::file(Path::of('src/B.php')),
        Unit::file(Path::of('src/C.php')),
        Unit::held(Path::of('src/D.php'), Group::holding('src/D.php')),
        Unit::file(Path::of('src/E.php')),
    );
    $now = $digested === false
        ? Undigested::proof()
        : PruningCases::now(...['src/A.php' => 'a', 'src/B.php' => 'b now', 'src/C.php' => 'c', 'src/D.php' => 'd', 'src/E.php' => 'e']);
    $pruned = $pruner->pruned(PruningCases::clean('Plus', 'Secure')->and(PruningCases::clean()), $units, $proofs->newest(), $now);

    return [array_map(static fn(Path $file): string => $file->value(), [...$pruned->files()]), [...$pruned->mutators()]];
}

$pruner = static fn(Pruning|Absent $settings = new Absent()): Pruner => Pruner::of(
    $settings instanceof Pruning ? $settings : Pruning::of(window: 2),
    Name::of('pest'),
    MutatorNames::of('Secure'),
    Moment::at('2026-10-01T00:00:00Z'),
);
$makeProofs = static fn(): Proofs => Proofs::of(
    PruningCases::proof('src/A.php', '2026-10-05T00:00:00Z', 'a', Mutants::none()),
    PruningCases::proof('src/B.php', '2026-10-05T00:00:00Z', 'b then', Mutants::none()),
    PruningCases::proof('src/C.php', '2026-09-20T00:00:00Z', 'c', Mutants::none()),
    PruningCases::proof('src/D.php', '2026-10-05T00:00:00Z', 'd', Mutants::none()),
);

it('prunes the clean mutators, less those never pruned, in each unit whose newest result is of its code and recent', function () use ($pruner, $makeProofs): void {
    $proofs = $makeProofs();

    expect(prunedOver($pruner(), $proofs))->toBe([['src/A.php'], ['Plus']]);
});

it('prunes in no unit whose result is of another mutant set', function () use ($pruner): void {
    $proofs = Proofs::of(PruningCases::proof('src/A.php', '2026-10-05T00:00:00Z', 'a', Mutants::none(), mutation: 'other mutators'));

    expect(prunedOver($pruner(), $proofs))->toBe([[], ['Plus']]);
});

it('carries a result made at the audit\'s very start', function () use ($pruner): void {
    $proofs = Proofs::of(PruningCases::proof('src/A.php', '2026-10-01T00:00:00Z', 'a', Mutants::none()));

    expect(prunedOver($pruner(), $proofs))->toBe([['src/A.php'], ['Plus']]);
});

it('prunes nothing where pruning is off, no mutator is clean, or the code on disk has no digests', function (Pruning $settings, bool $digested) use ($pruner, $makeProofs): void {
    $proofs = $makeProofs();

    expect(prunedOver($pruner($settings), $proofs, $digested))->toBe([[], []]);
})->with([
    'off' => [fn(): Pruning => Pruning::of(enabled: false, window: 2), true],
    'a window wider than every mutator learned' => [fn(): Pruning => Pruning::of(window: 3), true],
    'no digests' => [fn(): Pruning => Pruning::of(window: 2), false],
]);

it('says nothing is pruned where it prunes no mutator or no file', function (): void {
    expect(Pruned::none()->isNone())->toBeTrue()
        ->and(Pruned::of(MutatorNames::of('Plus'), Paths::none())->isNone())->toBeTrue()
        ->and(Pruned::of(MutatorNames::none(), Paths::of(Path::of('src/A.php')))->isNone())->toBeTrue();
});
