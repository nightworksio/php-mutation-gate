<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\PlannedMarkdown;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Plan\PlannedWork;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

it('says what the run mutates and what it should take, under the marker', function (): void {
    expect(PlannedMarkdown::comment(ShardedPlan::planned(2), 'https://github.example/octo/gate/actions/runs/7'))->toBe(<<<'MD'
        <!-- mutation-gate -->

        ## mutation-gate: planned

        Mutating 2 units in 2 shards: about 6m wall, 14m runner time (94% measured).

        <details><summary>Units (2)</summary>

        - <code>src/1.php</code>
        - <code>src/2.php</code>

        </details>

        [The run](https://github.example/octo/gate/actions/runs/7) replaces this with its verdict.

        MD);
});

it('counts one unit in one shard as one, and leaves out the link where there is none', function (): void {
    expect(PlannedMarkdown::comment(ShardedPlan::planned(1), ''))->toBe(<<<'MD'
        <!-- mutation-gate -->

        ## mutation-gate: planned

        Mutating 1 unit in 1 shard: about 6m wall, 14m runner time (94% measured).

        <details><summary>Units (1)</summary>

        - <code>src/1.php</code>

        </details>

        MD);
});

it('says there is nothing to mutate where the plan has no units', function (): void {
    $work = PlannedWork::of(
        ShardedPlan::of(0),
        RunTime::estimated(Seconds::of(0.0), Seconds::of(0.0)),
        Percentage::of(Floor::of(0)),
    );

    $comment = PlannedMarkdown::comment($work, '');

    expect($comment)->toContain(
        "## mutation-gate: planned\n\nNothing to mutate: the change reaches no unit, or a proof covers every unit it reaches.\n",
    )->and($comment)->not->toContain('<details>');
});

it('lists 20 units, and says how many more there are', function (): void {
    $comment = PlannedMarkdown::comment(ShardedPlan::planned(22), '');

    expect($comment)->toContain('<summary>Units (22)</summary>')
        ->and($comment)->toContain("- <code>src/20.php</code>\n\nAnd 2 more.\n\n</details>")
        ->and($comment)->not->toContain('src/21.php');
});
