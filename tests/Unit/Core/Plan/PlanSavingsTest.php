<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Price;
use NightWorksIO\MutationGate\Core\Cost\Cost;
use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Cost\Rate;
use NightWorksIO\MutationGate\Core\Cost\RunAccount;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\Savings;
use NightWorksIO\MutationGate\Core\Cost\ShardEstimate;
use NightWorksIO\MutationGate\Core\Cost\Unpriced;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanSavings;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Plan\StreamWall;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

/** What a cost model expects of a unit: its timing, or 5 s guessed where none timed it. */
function learnedOr(Timings $timings): Closure
{
    return static function (Unit $unit) use ($timings): Estimated {
        $timed = $timings->secondsFor($unit->path());

        return $timed instanceof Seconds
            ? Estimated::of($timed, CostBasis::Learned)
            : Estimated::of(Seconds::of(5.0), CostBasis::Guessed);
    };
}

$timing = static fn(string $unit, float $seconds): Timing => Timing::of(Path::of($unit), Seconds::of($seconds), 'pest', Instant::at(new DateTimeImmutable('2026-11-01T00:00:00Z')));
$plan = static fn(): Plan => Plan::of(Revision::ref('head'), Digest::ofNothing(), Keys::none(), Shards::none())
    ->considering(Considered::everything()
        ->carrying(Units::of(Unit::file(Path::of('src/Far.php')), Unit::file(Path::of('src/Untimed.php'))))
        ->proving(Units::of(Unit::file(Path::of('src/Proved.php')))));
$spent = static fn(): RunTime => RunTime::measured(Seconds::of(60.0), Seconds::of(90.0));

it('has no history to say anything from where no ledger holds a timing', function () use ($plan, $spent): void {
    expect(PlanSavings::of($plan(), Timings::none(), learnedOr(Timings::none()), Seconds::of(60.0), StreamWall::of(1))->saved($spent()))->toBeInstanceOf(NoHistory::class);
});

it('saves by reach what the units it carried cost, a unit no ledger timed estimated, and by proofs what those it proved did', function () use ($plan, $timing, $spent): void {
    $timings = Timings::of($timing('src/Far.php', 100.0), $timing('src/Proved.php', 20.0), $timing('src/Other.php', 7.0));
    $saved = PlanSavings::of($plan(), $timings, learnedOr($timings), Seconds::of(60.0), StreamWall::of(1))->saved($spent());

    expect($saved)->toBeInstanceOf(Savings::class)
        ->and($saved instanceof Savings ? [
            $saved->reach()->seconds(),
            $saved->proofs()->seconds(),
            $saved->fullRun()->seconds(),
            $saved->measured()->wholePercent(),
            $saved->isSharded(),
        ] : [])->toBe([105.0, 20.0, 185.0, 96, false]);
});

it('says what reach and proofs spared in what the run cost', function () use ($plan, $timing, $spent): void {
    $timings = Timings::of($timing('src/Far.php', 100.0), $timing('src/Proved.php', 20.0));
    $cost = PlanSavings::of($plan(), $timings, learnedOr($timings), Seconds::of(60.0), StreamWall::of(1))->cost($spent());

    expect($cost->spared()->seconds())->toBe(125.0)
        ->and($cost->measured())->toEqual($spent())
        ->and($cost->setup()->seconds())->toBe(60.0);
});

it('puts what the run cost, priced, and what it saved into the account', function () use ($plan, $timing, $spent): void {
    $saving = PlanSavings::of($plan(), Timings::of($timing('src/Far.php', 100.0)), learnedOr(Timings::of($timing('src/Far.php', 100.0))), Seconds::of(60.0), StreamWall::of(1));
    $priced = $saving->into(RunAccount::none(), $spent(), Price::of(0.008, 'USD'));
    $unpriced = $saving->into(RunAccount::none(), $spent(), Absent::setting());
    $cold = PlanSavings::of($plan(), Timings::none(), learnedOr(Timings::none()), Seconds::of(60.0), StreamWall::of(1))->into(RunAccount::none(), $spent(), Absent::setting());

    $priceOf = static fn(RunAccount $account): mixed => ($cost = $account->cost()) instanceof Cost ? $cost->price() : $cost;

    expect($priceOf($priced))->toEqual(Rate::perMinute(0.008, 'USD'))
        ->and($priced->savings())->toBeInstanceOf(Savings::class)
        ->and($priceOf($unpriced))->toEqual(Unpriced::time())
        ->and($cold->savings())->toBeInstanceOf(NoHistory::class);
});

it('counts each shard\'s units as the plan expected them, measured where a ledger timed them, one setup and one opening run against the setup and opening of every job past the first', function (bool $second, float $wall, array $expected) use ($timing): void {
    $shard = static fn(int $number, string $unit, float $cost, float $opening): Shard => Shard::of(
        ShardId::of($number),
        Package::at(Path::root()),
        Units::of(Unit::file(Path::of($unit))),
        Seconds::of($cost),
        'src',
    )->estimated(ShardEstimate::none()->with(Estimated::of(Seconds::of($cost), CostBasis::Learned))->opening(Seconds::of($opening)));
    $shards = $second
        ? Shards::of($shard(1, 'src/Timed.php', 40.0, 8.0), $shard(2, 'src/Unknown.php', 50.0, 12.0), Shard::empty(ShardId::of(3)))
        : Shards::of($shard(1, 'src/Timed.php', 40.0, 8.0), Shard::empty(ShardId::of(2)));
    $plan = Plan::of(Revision::ref('head'), Digest::ofNothing(), Keys::none(), $shards)
        ->considering(Considered::everything()
            ->carrying(Units::of(Unit::file(Path::of('src/Far.php')), Unit::file(Path::of('src/Untimed.php'))))
            ->proving(Units::of(Unit::file(Path::of('src/Proved.php')))));
    $timings = Timings::of($timing('src/Far.php', 100.0), $timing('src/Proved.php', 20.0), $timing('src/Timed.php', 30.0));
    $saved = PlanSavings::of($plan, $timings, learnedOr($timings), Seconds::of(60.0), StreamWall::of(1))->saved(RunTime::measured(Seconds::of($wall), Seconds::of(90.0)));

    expect($saved instanceof Savings ? [
        $saved->fullRun()->seconds(),
        $saved->measured()->hundredths(),
        $saved->isSharded(),
        $saved->waitSaved()->seconds(),
        $saved->shardingSetup()->seconds(),
    ] : [])->toBe($expected);
})->with([
    'two jobs, waiting less than one would' => [true, 200.0, [287.0, 6976, true, 87.0, 72.0]],
    'two jobs, waiting longer than one would' => [true, 300.0, [287.0, 6976, true, 0.0, 72.0]],
    'one job' => [false, 200.0, [233.0, 9090, false, 0.0, 0.0]],
]);
