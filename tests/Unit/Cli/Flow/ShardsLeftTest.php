<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\ShardLeft;
use NightWorksIO\MutationGate\Cli\Flow\ShardsLeft;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
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
use NightWorksIO\MutationGate\Core\Written;

/** What an item of this shard left, written where its result goes, its run doomed or not. */
function shardLeftOf(int $id, bool $doomed): ShardLeft
{
    $result = ShardResult::of(
        Digest::sha256Of('plan'),
        ShardId::of($id),
        Keys::none(),
        MutationResult::of(Mutants::of(), 0),
        Measurement::of(Seconds::of(1.0), 'fake', Instant::at(new DateTimeImmutable('2026-11-05T12:00:00Z'))),
    );
    $left = ShardLeft::after(
        $doomed ? $result->withDoomed(Doomed::of(Path::of('src/Money.php'), MutantId::hash(Path::of('src/Money.php'), 'Plus', '1', 0), Path::of('src'), Floor::whole(), DoomedBy::Tree)) : $result,
        Written::to(sprintf('.mutation-gate/results/%d.json', $id)),
    );

    return $left instanceof ShardLeft ? $left : throw new LogicException($left->why());
}

it('says why the job fails from the first item whose result leaves the run no way to pass, and nothing where none does', function (): void {
    $passing = ShardsLeft::none(ShardId::of(1))->with(shardLeftOf(1, doomed: false));
    $doomed = $passing->with(shardLeftOf(2, doomed: true));

    expect($passing->doomed())->toBe('')
        ->and($doomed->doomed())->toBe(shardLeftOf(2, doomed: true)->doomed())
        ->and($doomed->doomed())->toStartWith('Shard 2 ');
});

it('says where each result was written after why the job ran its own items alone, once, and why it started no further item', function (): void {
    $left = ShardsLeft::none(ShardId::of(3))
        ->alone(CannotJudge::because('The claims cannot be read.'))
        ->alone(CannotJudge::because('Nor written.'))
        ->with(shardLeftOf(3, doomed: false))
        ->stopped('A change arrived.');

    expect($left->said())->toBe(implode("\n", [
        'Shard 3 runs its own items alone: The claims cannot be read.',
        'A change arrived.',
        'Wrote .mutation-gate/results/3.json.',
    ]))
        ->and(ShardsLeft::none(ShardId::of(3))->said())->toBe('Shard 3 ran no item: the other shards took every one.');
});
