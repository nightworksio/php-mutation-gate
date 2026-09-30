<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('holds the plan it followed, its units and keys, what the runner reported and what it measured', function (): void {
    $plan = Digest::of('9c1e');
    $shard = ShardId::of(2);
    $units = Keys::none()->with(Path::of('src/Money.php'), Digest::of('aaa'));
    $outcome = MutationResult::of(Mutants::none(), 0);
    $measured = Measurement::of(Seconds::of(42.5), 'pest', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')));
    $result = ShardResult::of($plan, $shard, $units, $outcome, $measured);

    expect($result->plan())->toBe($plan)
        ->and($result->shard())->toBe($shard)
        ->and($result->units())->toBe($units)
        ->and($result->outcome())->toBe($outcome)
        ->and($result->measured())->toBe($measured)
        ->and($result->flaky())->toHaveCount(0);
});

it('takes the ids of the mutants that gave two answers, keeping everything else', function (): void {
    $units = Keys::none()->with(Path::of('src/Money.php'), Digest::of('aaa'));
    $outcome = MutationResult::of(Mutants::none(), 0);
    $measured = Measurement::of(Seconds::of(42.5), 'pest', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')));
    $flaky = MutantIds::of(MutantId::hash(Path::of('src/Money.php'), 'Plus', '', 0));
    $result = ShardResult::of(Digest::of('9c1e'), ShardId::of(2), $units, $outcome, $measured)->withFlaky($flaky);

    expect($result->flaky())->toBe($flaky)
        ->and($result->plan())->toEqual(Digest::of('9c1e'))
        ->and($result->shard())->toEqual(ShardId::of(2))
        ->and($result->units())->toBe($units)
        ->and($result->outcome())->toBe($outcome)
        ->and($result->measured())->toBe($measured);
});

it('may hold why the runner could not judge instead', function (): void {
    $why = CannotJudge::because('The opening run failed.');
    $measured = Measurement::of(Seconds::of(1.0), 'pest', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')));

    expect(ShardResult::of(Digest::of('9c1e'), ShardId::of(1), Keys::none(), $why, $measured)->outcome())->toBe($why);
});
