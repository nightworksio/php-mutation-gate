<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Plan\FailFastSavings;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\DoomedBy;
use NightWorksIO\MutationGate\Core\Verdict\Warning;

/** A shard's result that spent this long mutating, doomed this far in where it is, stopping there or judging on. */
$shard = static function (int $id, float $spent, float $doomedAfter = -1.0, bool $stopped = false): ShardResult {
    $result = ShardResult::of(
        Digest::sha256Of('plan'),
        ShardId::of($id),
        Keys::none(),
        MutationResult::of(Mutants::of(), 0),
        Measurement::of(Seconds::of($spent), 'fake', Instant::at(new DateTimeImmutable('2026-11-05T12:00:00Z'))),
    );
    $doomed = Doomed::of(Path::of('src/Money.php'), MutantId::hash(Path::of('src/Money.php'), 'Plus', '1', 0), Path::of('src'), Floor::whole(), DoomedBy::Tree);

    return $doomedAfter < 0 ? $result : $result->withDoomed($doomed->found(Seconds::of($doomedAfter), $stopped));
};

$said = static fn(FailFastSavings $savings): array => array_map(static fn(Warning $warning): string => $warning->text(), [...$savings->warnings()]);

it('says what stop and cancel would have saved shards that judged on past the survivor that doomed their run', function () use ($shard, $said): void {
    $savings = FailFastSavings::of([$shard(1, 1800.0, 600.0), $shard(2, 1500.0), $shard(3, 1200.0, 900.0)]);

    expect($said($savings))->toBe([implode("\n", [
        'This run could not pass 10m into its shards\' mutating, and they judged on, as run.failFast: mark has them.',
        'run.failFast: stop would have saved about 25m of runner time, and cancel about 45m.',
    ])]);
});

it('counts to the second, clamping a shard that ended before the doom it is measured from to nothing', function () use ($shard, $said): void {
    $savings = FailFastSavings::of([$shard(1, 30.0, 10.0), $shard(2, 5.0), $shard(3, 20.0, 25.0), $shard(4, 15.0)]);

    expect($said($savings))->toBe([implode("\n", [
        'This run could not pass 10s into its shards\' mutating, and they judged on, as run.failFast: mark has them.',
        'run.failFast: stop would have saved about 20s of runner time, and cancel about 35s.',
    ])]);
});

it('says nothing where no shard was doomed, or where the doomed shard stopped', function () use ($shard, $said): void {
    expect($said(FailFastSavings::of([$shard(1, 60.0), $shard(2, 60.0)])))->toBe([])
        ->and($said(FailFastSavings::of([$shard(1, 60.0, 10.0, stopped: true), $shard(2, 60.0)])))->toBe([])
        ->and($said(FailFastSavings::of([])))->toBe([]);
});

it('saves nothing of a shard that ended before the first doom', function () use ($shard, $said): void {
    expect($said(FailFastSavings::of([$shard(1, 600.0, 300.0), $shard(2, 120.0)])))->toBe([implode("\n", [
        'This run could not pass 5m into its shards\' mutating, and they judged on, as run.failFast: mark has them.',
        'run.failFast: stop would have saved about 5m of runner time, and cancel about 5m.',
    ])]);
});
