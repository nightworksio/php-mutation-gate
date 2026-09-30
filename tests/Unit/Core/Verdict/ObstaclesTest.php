<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Verdict\Obstacles;

it('holds nothing to begin with', function (): void {
    expect(Obstacles::none())->toHaveCount(0);
});

it('keeps what kept a run from judging in the order it was met, without changing what it came from', function (): void {
    $first = CannotJudge::because('Shard 2 wrote no result.');
    $second = CannotJudge::because('Shard 3 ran on another commit.');
    $one = Obstacles::none()->with($first);

    expect([...$one->with($second)])->toBe([$first, $second])
        ->and($one)->toHaveCount(1);
});
