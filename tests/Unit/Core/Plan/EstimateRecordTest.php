<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Cost\ShardEstimate;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Plan\EstimateRecord;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('writes a guess as its opening run alone, and anything more as each basis', function (): void {
    $guessed = ShardEstimate::none()->with(Estimated::of(Seconds::of(3.0), CostBasis::Guessed))->opening(Seconds::of(5.0));
    $mixed = $guessed->with(Estimated::of(Seconds::of(2.0), CostBasis::Learned));

    expect(EstimateRecord::of($guessed))->toBe(['opening' => 5.0])
        ->and(EstimateRecord::of($mixed))->toBe(['learned' => 2.0, 'guessed' => 3.0, 'opening' => 5.0])
        ->and(EstimateRecord::of(ShardEstimate::none()))->toBe([]);
});

it('reads back what it wrote, and a shard that names no basis as a guess of its seconds', function (): void {
    $mixed = ShardEstimate::none()
        ->with(Estimated::of(Seconds::of(2.0), CostBasis::Learned))
        ->with(Estimated::of(Seconds::of(3.0), CostBasis::Guessed))
        ->opening(Seconds::of(5.0));
    $read = static fn(string $json, float $seconds): ShardEstimate => EstimateRecord::read(
        Node::decode($json),
        Seconds::of($seconds),
    );

    expect($read((string) json_encode(EstimateRecord::of($mixed)), 5.0))->toEqual($mixed)
        ->and($read('{}', 4.0))->toEqual(ShardEstimate::none()->with(Estimated::of(Seconds::of(4.0), CostBasis::Guessed)));
});
