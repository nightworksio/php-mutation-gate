<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Order\Risk;
use NightWorksIO\MutationGate\Core\Order\RiskOrder;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\MutantTriage;
use NightWorksIO\MutationGate\Tests\Support\Moment;

/** A unit's one mutant, on its first line, ended so. */
function riskMutant(string $unit, MutantStatus $status): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of($unit), 'LessThan', '1', 0),
        sprintf('%s:1', $unit),
        Location::of(Path::of($unit), Line::of(1), Line::of(1)),
        Mutation::of('LessThan', MutatorFamily::Boundary, ''),
        $status,
        Unmeasured::duration(),
    );
}

/** A proof of a unit whose one reported mutant ended so. */
function riskProof(string $unit, MutantStatus $status): Proof
{
    return riskProofOf($unit, riskMutant($unit, $status));
}

/**
 * A proof of a unit whose one reported mutant timed out at this limit, its
 * judging tests taking this long unmutated under it, or never finishing
 * within it where they take none.
 */
function riskTimeout(string $unit, float $limit, float $time): Proof
{
    $timedOut = riskMutant($unit, MutantStatus::TimedOut)->withLimit(Seconds::of($limit));

    return riskProofOf($unit, $time > 0.0 ? $timedOut->withUnmutatedNeed(Seconds::of($time)) : $timedOut);
}

/**
 * A proof of a unit whose one reported mutant ran out of a cap of this many
 * megabytes, its control holding this many under it, or never finishing
 * under it where it held none.
 */
function riskHeavy(string $unit, int $cap, int $peak): Proof
{
    $heavy = riskMutant($unit, MutantStatus::OutOfMemory)->withLimit(MemoryCap::of($cap, MemoryUnit::Megabytes));

    return riskProofOf($unit, $peak > 0 ? $heavy->withUnmutatedNeed(MemoryCap::of($peak, MemoryUnit::Megabytes)) : $heavy);
}

/** A proof of a unit with this one mutant. */
function riskProofOf(string $unit, Mutant $mutant): Proof
{
    return Proof::of(
        Digest::sha256Of($unit),
        Path::of($unit),
        Mutants::of($mutant),
        Run::of('main', Instant::at(new DateTimeImmutable('2026-09-30T10:00:00Z')), Digest::sha256Of('base')),
    );
}

$trees = static fn(): Trees => Trees::of(Tree::at(Path::of('src'), Floor::of(50), Package::at(Path::root())));
$reach = static fn(): Reach => Reach::nothing(Packages::of($trees()))
    ->files(Paths::of(Path::of('src/Reached.php'), Path::of('src/Changed.php')), Reason::that('A changed test runs them.'))
    ->withLines(Path::of('src/Changed.php'), Lines::of(Line::of(4)))
    ->withLines(Path::of('src/Edited/Kept.php'), Lines::of(Line::of(2)));
$makeOrder = static fn(): RiskOrder => RiskOrder::of(
    $reach(),
    Proofs::of(
        riskProof('src/Changed.php', MutantStatus::Survived),
        riskProof('src/Survivor.php', MutantStatus::Survived),
        riskTimeout('src/TooSlow.php', 10.0, 0.0),
        riskTimeout('src/KilledByTimeout.php', 10.0, 1.0),
        riskHeavy('src/TooHeavy.php', 64, 0),
        riskHeavy('src/KilledByMemoryCap.php', 64, 20),
        riskProof('src/Reached.php', MutantStatus::Killed),
        riskProof('src/Settled.php', MutantStatus::Killed),
        riskProof('src/Held', MutantStatus::Killed),
    )->newest(),
    MutantTriage::under(TimeoutMode::Confirm),
);

it('ranks a unit by the first risk it runs', function (Unit $unit, Risk $risk) use ($makeOrder): void {
    $order = $makeOrder();

    expect($order->riskOf($unit))->toBe($risk);
})->with([
    'changed lines, whatever its last result' => [fn(): Unit => Unit::file(Path::of('src/Changed.php')), Risk::ChangedLines],
    'changed lines of a file a held unit holds' => [fn(): Unit => Unit::held(Path::of('src/Edited'), Group::named('holds:src/Edited')), Risk::ChangedLines],
    'a survivor last time' => [fn(): Unit => Unit::file(Path::of('src/Survivor.php')), Risk::Unsettled],
    'a mutant too slow to judge last time' => [fn(): Unit => Unit::file(Path::of('src/TooSlow.php')), Risk::Unsettled],
    'a mutant too heavy to judge last time' => [fn(): Unit => Unit::file(Path::of('src/TooHeavy.php')), Risk::Unsettled],
    'a kill by the memory cap, and nothing else' => [fn(): Unit => Unit::file(Path::of('src/KilledByMemoryCap.php')), Risk::Rest],
    'never mutated' => [fn(): Unit => Unit::file(Path::of('src/New.php')), Risk::NeverMutated],
    'reached by a changed test' => [fn(): Unit => Unit::file(Path::of('src/Reached.php')), Risk::Reached],
    'a timeout that killed it, and nothing else' => [fn(): Unit => Unit::file(Path::of('src/KilledByTimeout.php')), Risk::Rest],
    'everything settled and unreached' => [fn(): Unit => Unit::file(Path::of('src/Settled.php')), Risk::Rest],
]);

it('orders units the riskiest first, and by path between units of one risk', function () use ($makeOrder): void {
    $order = $makeOrder();

    $held = Unit::held(Path::of('src/Held'), Group::named('holds:src/Held'));
    $ordered = $order->ordered(Units::of(
        Unit::file(Path::of('src/Settled.php')),
        $held,
        Unit::file(Path::of('src/Reached.php')),
        Unit::file(Path::of('src/New.php')),
        Unit::file(Path::of('src/TooSlow.php')),
        Unit::file(Path::of('src/Survivor.php')),
        Unit::file(Path::of('src/Changed.php')),
    ));

    expect(array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$ordered]))->toBe([
        'src/Changed.php',
        'src/Survivor.php',
        'src/TooSlow.php',
        'src/New.php',
        'src/Reached.php',
        'src/Held',
        'src/Settled.php',
    ]);
});

it('names the units of least risk, which are the ones recency orders', function () use ($makeOrder): void {
    $order = $makeOrder();

    $least = $order->least(Units::of(
        Unit::file(Path::of('src/Settled.php')),
        Unit::file(Path::of('src/Changed.php')),
        Unit::file(Path::of('src/KilledByTimeout.php')),
        Unit::file(Path::of('src/New.php')),
    ));

    expect(array_map(static fn(Path $path): string => $path->value(), [...$least]))
        ->toBe(['src/Settled.php', 'src/KilledByTimeout.php']);
});

it('puts the most recently changed first among the least risky, those no commit changed after them, and ties by path', function () use ($makeOrder): void {
    $order = $makeOrder();

    $at = Moment::at(...);
    $ordered = $order
        ->knowing(ByPath::none()
            ->with(Path::of('src/Held'), $at('2026-09-01T10:00:00Z'))
            ->with(Path::of('src/Settled.php'), $at('2026-09-20T10:00:00Z'))
            ->with(Path::of('src/KilledByTimeout.php'), $at('2026-09-01T10:00:00Z'))
            ->with(Path::of('src/Survivor.php'), $at('2026-09-29T10:00:00Z')))
        ->ordered(Units::of(
            Unit::file(Path::of('src/Unlogged.php')),
            Unit::file(Path::of('src/KilledByTimeout.php')),
            Unit::held(Path::of('src/Held'), Group::named('holds:src/Held')),
            Unit::file(Path::of('src/Settled.php')),
            Unit::file(Path::of('src/Reached.php')),
            Unit::file(Path::of('src/TooSlow.php')),
            Unit::file(Path::of('src/Survivor.php')),
        ));

    expect(array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$ordered]))->toBe([
        'src/Survivor.php',
        'src/TooSlow.php',
        'src/Unlogged.php',
        'src/Reached.php',
        'src/Settled.php',
        'src/Held',
        'src/KilledByTimeout.php',
    ]);
});
