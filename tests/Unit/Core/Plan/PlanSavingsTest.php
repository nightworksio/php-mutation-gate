<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Price;
use NightWorksIO\MutationGate\Core\Cost\Cost;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Cost\Rate;
use NightWorksIO\MutationGate\Core\Cost\RunAccount;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\Savings;
use NightWorksIO\MutationGate\Core\Cost\Unpriced;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanSavings;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

$timing = static fn(string $unit, float $seconds): Timing => Timing::of(Path::of($unit), Seconds::of($seconds), 'pest', Instant::at(new DateTimeImmutable('2026-11-01T00:00:00Z')));
$plan = static fn(): Plan => Plan::of(Revision::ref('head'), Digest::ofNothing(), Keys::none(), Shards::none())
    ->considering(Considered::everything()
        ->carrying(Units::of(Unit::file(Path::of('src/Far.php')), Unit::file(Path::of('src/Untimed.php'))))
        ->proving(Units::of(Unit::file(Path::of('src/Proved.php')))));
$spent = static fn(): RunTime => RunTime::measured(Seconds::of(60.0), Seconds::of(90.0));

it('has no history to say anything from where no ledger holds a timing', function () use ($plan, $spent): void {
    expect(PlanSavings::of($plan(), Timings::none(), Seconds::of(60.0))->saved($spent()))->toBeInstanceOf(NoHistory::class);
});

it('saves by reach the timings of the units it carried, and by proofs those of the units it proved', function () use ($plan, $timing, $spent): void {
    $timings = Timings::of($timing('src/Far.php', 100.0), $timing('src/Proved.php', 20.0), $timing('src/Other.php', 7.0));
    $saved = PlanSavings::of($plan(), $timings, Seconds::of(60.0))->saved($spent());

    expect($saved)->toBeInstanceOf(Savings::class)
        ->and($saved instanceof Savings ? [
            $saved->reach()->seconds(),
            $saved->proofs()->seconds(),
            $saved->fullRun()->seconds(),
            $saved->measured()->wholePercent(),
            $saved->isSharded(),
        ] : [])->toBe([100.0, 20.0, 180.0, 100, false]);
});

it('says what reach and proofs spared in what the run cost', function () use ($plan, $timing, $spent): void {
    $timings = Timings::of($timing('src/Far.php', 100.0), $timing('src/Proved.php', 20.0));
    $cost = PlanSavings::of($plan(), $timings, Seconds::of(60.0))->cost($spent());

    expect($cost->spared()->seconds())->toBe(120.0)
        ->and($cost->measured())->toEqual($spent())
        ->and($cost->setup()->seconds())->toBe(60.0);
});

it('puts what the run cost, priced, and what it saved into the account', function () use ($plan, $timing, $spent): void {
    $saving = PlanSavings::of($plan(), Timings::of($timing('src/Far.php', 100.0)), Seconds::of(60.0));
    $priced = $saving->into(RunAccount::none(), $spent(), Price::of(0.008, 'USD'));
    $unpriced = $saving->into(RunAccount::none(), $spent(), Absent::setting());
    $cold = PlanSavings::of($plan(), Timings::none(), Seconds::of(60.0))->into(RunAccount::none(), $spent(), Absent::setting());

    $priceOf = static fn(RunAccount $account): mixed => ($cost = $account->cost()) instanceof Cost ? $cost->price() : $cost;

    expect($priceOf($priced))->toEqual(Rate::perMinute(0.008, 'USD'))
        ->and($priced->savings())->toBeInstanceOf(Savings::class)
        ->and($priceOf($unpriced))->toEqual(Unpriced::time())
        ->and($cold->savings())->toBeInstanceOf(NoHistory::class);
});
