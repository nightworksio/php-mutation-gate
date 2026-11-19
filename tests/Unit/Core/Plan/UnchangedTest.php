<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\IgnoredPattern;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\StandsBecause;
use NightWorksIO\MutationGate\Core\Plan\Unchanged;
use NightWorksIO\MutationGate\Core\Proof\GateRelease;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Time\Day;
use NightWorksIO\MutationGate\Core\Time\Instant;

const UNCHANGED_COMMIT = '5eeca8f0a1b2c3d4e5f60718293a4b5c6d7e8f90';

$instant = static fn(string $at): Instant => Instant::parse($at) instanceof Instant
    ? Instant::parse($at)
    : Instant::at(new DateTimeImmutable('@0'));

$unchanged = static fn(string $at = '2026-10-01T12:00:00Z'): Unchanged => Unchanged::since(
    Passed::of(Revision::ref(UNCHANGED_COMMIT), 'mutation / verdict', 2)->passedAt($instant($at)),
    $instant($at),
);

$ignoreUntil = static fn(string $day): IgnoredPattern => IgnoredPattern::of(
    Glob::of('src/**'),
    'boundary',
    'The bound is never reached',
    Day::of($day) instanceof Day ? Day::of($day) : Absent::setting(),
);

it('says the verdict of the commit that passed stands, why, and when it passed', function (StandsBecause $because, string $said) use ($instant): void {
    $at = $instant('2026-10-01T12:00:00Z');
    $standing = Unchanged::standing($because, Passed::of(Revision::ref(UNCHANGED_COMMIT), 'mutation / verdict', 2)->passedAt($at), $at);

    expect($standing->reason()->text())->toBe(sprintf($said, UNCHANGED_COMMIT))
        ->and($standing->because())->toBe($because);
})->with([
    'nothing judged changed' => [StandsBecause::NothingJudgedChanged, 'Nothing the gate judges changed since %s, whose verdict passed at 2026-10-01T12:00:00Z, so its verdict stands.'],
    'nothing reached' => [StandsBecause::NothingReached, 'The change since %s, whose verdict passed at 2026-10-01T12:00:00Z, reaches no unit, so its verdict stands.'],
]);

it('says whose earlier verdict stands, naming its commit', function (StandsBecause $because, string $said) use ($instant): void {
    $at = $instant('2026-10-01T12:00:00Z');

    expect(Unchanged::standing($because, Passed::of(Revision::ref(UNCHANGED_COMMIT), 'mutation / verdict', 2)->passedAt($at), $at)->headline())
        ->toBe(sprintf($said, UNCHANGED_COMMIT));
})->with([
    'nothing judged changed' => [StandsBecause::NothingJudgedChanged, 'The earlier verdict at %s stands.'],
    'nothing reached' => [StandsBecause::NothingReached, 'The default branch\'s earlier verdict at %s stands.'],
]);

it('stands because nothing the gate judges changed, where it is made since a commit alone', function () use ($unchanged): void {
    expect($unchanged()->because())->toBe(StandsBecause::NothingJudgedChanged);
});

it('records the commit the plan was made on as passed, under the check it stands on passed under, using what of its scope that commit used', function () use ($unchanged, $instant): void {
    $own = Unchanged::since(
        Passed::of(Revision::ref(UNCHANGED_COMMIT), 'mutation / verdict', 0)->onOwnScopeCoverage(),
        $instant('2026-10-01T12:00:00Z'),
    );
    $at = $instant('2026-10-10T08:00:00Z');

    expect($unchanged()->passing(Revision::ref('head'), $at))
        ->toEqual(Passed::of(Revision::ref('head'), 'mutation / verdict', 2)->passedAt($at))
        ->and($own->passing(Revision::ref('head'), $at))
        ->toEqual(Passed::of(Revision::ref('head'), 'mutation / verdict', 0)->passedAt($at)->onOwnScopeCoverage());
});

it('finds the first ignore that applied when the commit passed and has expired by now', function () use ($unchanged, $ignoreUntil): void {
    $expired = $ignoreUntil('2026-10-05');
    $now = new DateTimeImmutable('2026-10-10T12:00:00Z');

    expect($unchanged()->expiredBy(Listed::of($ignoreUntil('2026-12-31'), $expired, $ignoreUntil('2026-10-06')), $now))->toBe($expired)
        ->and($unchanged()->expiredBy(Listed::of($ignoreUntil('2026-09-30'), $ignoreUntil('2026-12-31')), $now))->toEqual(NotGiven::value())
        ->and($unchanged()->expiredBy(Listed::of(), $now))->toEqual(NotGiven::value());
});

it('judges an ignore on the days of the clock that reads now, wherever the commit passed', function () use ($unchanged, $ignoreUntil): void {
    $now = new DateTimeImmutable('2026-10-10 12:00:00', new DateTimeZone('Europe/Amsterdam'));

    expect($unchanged('2026-10-01T23:30:00Z')->expiredBy(Listed::of($ignoreUntil('2026-10-01')), $now))->toEqual(NotGiven::value())
        ->and($unchanged('2026-10-01T21:30:00Z')->expiredBy(Listed::of($ignoreUntil('2026-10-01')), $now))->toBeInstanceOf(IgnoredPattern::class);
});

it('takes an ignore as one that applied where when the commit passed is no moment of the calendar', function () use ($unchanged, $ignoreUntil): void {
    $ignore = $ignoreUntil('2026-01-15');

    expect($unchanged('2026-02-30T12:00:00Z')->expiredBy(Listed::of($ignore), new DateTimeImmutable('2026-10-10T12:00:00Z')))->toBe($ignore);
});

it('never finds an ignore that does not expire', function () use ($unchanged): void {
    $forever = IgnoredPattern::of(Glob::of('src/**'), 'boundary', 'The bound is never reached', Absent::setting());

    expect($unchanged()->expiredBy(Listed::of($forever), new DateTimeImmutable('2099-01-01T00:00:00Z')))->toEqual(NotGiven::value());
});

it('records the commit it stands for as passed by the release that judged the commit it stands on', function (): void {
    $at = Instant::at(new DateTimeImmutable('2026-10-10T07:30:00Z'));
    $base = Passed::of(Revision::ref(UNCHANGED_COMMIT), 'mutation / verdict', 2)
        ->passedAt($at)
        ->judgedBy(GateRelease::spelt('v0.1.0'));

    expect(Unchanged::since($base, $at)->passing(Revision::ref('head'), $at)->gate())->toEqual(GateRelease::spelt('v0.1.0'));
});
