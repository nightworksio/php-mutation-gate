<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\PlannedMarkdown;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\PlannedWork;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

it('says what the run mutates, what it should take, and the lines no test covers, under the marker', function (): void {
    $work = ShardedPlan::planned(2)
        ->withUncovered(Path::of('src/1.php'), Lines::of(Line::of(12), Line::of(14)))
        ->withUncovered(Path::of('src/2.php'), Lines::of(Line::of(3)));

    expect(PlannedMarkdown::comment($work, 'https://github.example/octo/gate/actions/runs/7'))->toBe(<<<'MD'
        <!-- mutation-gate -->

        ## mutation-gate: planned

        Mutating 2 units in 2 shards: about 6m wall, 14m runner time (94% measured).

        <details><summary>Units (2)</summary>

        - <code>src/1.php</code>
        - <code>src/2.php</code>

        </details>

        ### Changed lines no test covers (3)

        - <code>src/1.php:12</code>
        - <code>src/1.php:14</code>
        - <code>src/2.php:3</code>

        [The run](https://github.example/octo/gate/actions/runs/7) replaces this with its verdict.

        MD);
});

it('counts one unit in one shard as one, and leaves out the lines and the link where there are none', function (): void {
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

it('lists 20 units and 20 lines, and says how many more there are', function (): void {
    $lines = [];

    foreach (range(1, 23) as $number) {
        $lines[] = Line::of($number);
    }

    $comment = PlannedMarkdown::comment(
        ShardedPlan::planned(22)->withUncovered(Path::of('src/1.php'), Lines::of(...$lines)),
        '',
    );

    expect($comment)->toContain('<summary>Units (22)</summary>')
        ->and($comment)->toContain("- <code>src/20.php</code>\n\nAnd 2 more.\n\n</details>")
        ->and($comment)->not->toContain('src/21.php')
        ->and($comment)->toContain("### Changed lines no test covers (23)\n\n")
        ->and($comment)->toContain("- <code>src/1.php:20</code>\n\nAnd 3 more.\n")
        ->and($comment)->not->toContain('src/1.php:21');
});
