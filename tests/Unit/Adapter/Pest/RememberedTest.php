<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Remembered;
use NightWorksIO\MutationGate\Adapter\Pest\ReplayVerdict;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\ControlEnd;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\ListingStarts;
use NightWorksIO\MutationGate\Tests\Support\ReplayRuns;

it('learns each thing once, and each listing once for its suites and what it withheld', function (): void {
    $remembered = new Remembered();
    $asked = [];
    $listing = static function (string $name) use (&$asked): Closure {
        return static function () use ($name, &$asked): TestListing {
            $asked[] = $name;

            return TestListing::of(TestIds::of(TestId::of($name)));
        };
    };

    $first = $remembered->listing(Withheld::standard(), Suites::all(), $listing('first'));
    $again = $remembered->listing(Withheld::standard(), Suites::all(), $listing('again'));
    $other = $remembered->listing(Withheld::of('DEPLOY_*'), Suites::all(), $listing('other'));
    $suites = $remembered->listing(Withheld::standard(), Suites::listed('Process'), $listing('suites'));
    $suitesAgain = $remembered->listing(Withheld::standard(), Suites::listed('Process'), $listing('suites again'));
    $patched = [$remembered->patched(static fn(): bool => true), $remembered->patched(static fn(): bool => false)];
    $map = $remembered->map(Path::of('planned'), static fn(): CoverageMap => CoverageMap::of());
    $mapAgain = $remembered->map(Path::of('planned'), static fn(): CannotJudge => CannotJudge::because('read again'));
    $written = 0;
    $remembered->writeOnce('/m', static function () use (&$written): void {
        $written++;
    });
    $remembered->writeOnce('/m', static function () use (&$written): void {
        $written++;
    });

    expect($first)->toBe($again)
        ->and($other)->not->toBe($first)
        ->and($suites)->toBe($suitesAgain)
        ->and($asked)->toBe(['first', 'other', 'suites'])
        ->and($patched)->toBe([true, true])
        ->and($mapAgain)->toBe($map)
        ->and($written)->toBe(1);
});

it('lists together only the sets of suites not yet listed, each once, and answers each in the order asked', function (): void {
    $remembered = new Remembered();
    $starts = new ListingStarts();

    $known = $remembered->listing(Withheld::standard(), Suites::listed('Unit'), static fn(): TestListing => TestListing::none());
    $listed = $remembered->listings(Withheld::standard(), $starts->starting(), Suites::listed('Process'), Suites::listed('Unit'), Suites::listed('Process'))->taken();
    $again = $remembered->listings(Withheld::standard(), $starts->starting(), Suites::listed('Unit'), Suites::listed('Process'))->taken();

    expect($listed[1])->toBe($known)
        ->and($listed[0])->toBe($listed[2])
        ->and($listed[0])->toEqual(TestListing::of(TestIds::of(TestId::of(Suites::listed('Process')->joined()))))
        ->and($again)->toBe([$known, $listed[0]])
        ->and($starts->asked)->toBe([[Suites::listed('Process')->joined()]])
        ->and($starts->stopped)->toBe(0);
});

it('answers a set of suites started and not yet taken with what it lists, and starts it no second time', function (): void {
    $remembered = new Remembered();
    $starts = new ListingStarts();

    $pending = $remembered->listings(Withheld::standard(), $starts->starting(), Suites::listed('Unit'), Suites::listed('Process'));
    $again = $remembered->listings(Withheld::standard(), $starts->starting(), Suites::listed('Process'));
    $alone = $remembered->listing(Withheld::standard(), Suites::listed('Unit'), static fn(): TestListing => TestListing::none());

    expect($alone)->toEqual(TestListing::of(TestIds::of(TestId::of(Suites::listed('Unit')->joined()))))
        ->and($again->taken())->toBe([$pending->taken()[1]])
        ->and($pending->taken()[0])->toBe($alone)
        ->and($starts->asked)->toBe([[Suites::listed('Unit')->joined(), Suites::listed('Process')->joined()]]);
});

it('lists a set of suites anew where the listing it waited on was stopped by those that started it', function (): void {
    $remembered = new Remembered();
    $starts = new ListingStarts();

    $first = $remembered->listings(Withheld::standard(), $starts->starting(), Suites::listed('Unit'));
    $waiting = $remembered->listings(Withheld::standard(), $starts->starting(), Suites::listed('Unit'), Suites::listed('Process'));
    $first->stop();

    expect($waiting->taken())->toEqual([
        TestListing::of(TestIds::of(TestId::of(Suites::listed('Unit')->joined()))),
        TestListing::of(TestIds::of(TestId::of(Suites::listed('Process')->joined()))),
    ])
        ->and($starts->asked)->toBe([
            [Suites::listed('Unit')->joined()],
            [Suites::listed('Process')->joined()],
            [Suites::listed('Unit')->joined()],
        ])
        ->and($starts->stopped)->toBe(1);
});

it('stops the listings it started when stopped, and forgets them, so a set of suites asked after lists anew', function (): void {
    $remembered = new Remembered();
    $starts = new ListingStarts();

    $remembered->listings(Withheld::standard(), $starts->starting(), Suites::listed('Unit'))->stop();
    $alone = $remembered->listing(Withheld::standard(), Suites::listed('Unit'), static fn(): TestListing => TestListing::none());
    $remembered->listings(Withheld::standard(), $starts->starting(), Suites::listed('Unit'))->stop();

    expect($starts->stopped)->toBe(1)
        ->and($alone)->toEqual(TestListing::none())
        ->and($starts->asked)->toBe([[Suites::listed('Unit')->joined()]]);
});

it('runs each baseline once, all it does not know in one call, and keeps nothing of one stopped or never started', function (): void {
    $remembered = new Remembered();
    $asked = [];
    $failed = ControlRun::failed(TestIds::of(TestId::of('Tests\MoneyTest::testAdds')));
    $running = static function (ControlRun ...$ends) use (&$asked): Closure {
        return static function (array $keys) use ($ends, &$asked): array {
            $asked[] = $keys;

            return array_values($ends);
        };
    };
    $ends = static function (array $runs): array {
        $ended = [];

        foreach ($runs as $run) {
            $ended[] = $run instanceof ControlRun ? $run->end() : $run;
        }

        return $ended;
    };

    $first = $remembered->baselines(
        ['passes', 'fails', 'passes', 'stopped', 'unstarted'],
        $running(ControlRun::passed(Seconds::of(1.0)), $failed, ControlRun::ranOut()),
    );
    $again = $remembered->baselines(
        ['fails', 'stopped', 'passes', 'unstarted'],
        $running(ControlRun::passed(Seconds::of(1.0)), ControlRun::passed(Seconds::of(1.0))),
    );
    $known = $remembered->baselines(['passes'], $running());

    expect([$ends($first), $ends($again), $ends($known)])->toBe([
        [ControlEnd::Passed, ControlEnd::Failed, ControlEnd::Passed, ControlEnd::RanOut, ControlEnd::Unrun],
        [ControlEnd::Failed, ControlEnd::Passed, ControlEnd::Passed, ControlEnd::Passed],
        [ControlEnd::Passed],
    ])
        ->and($first[1])->toBe($failed)
        ->and($again[0])->toBe($failed)
        ->and($asked)->toBe([['passes', 'fails', 'stopped', 'unstarted'], ['stopped', 'unstarted']]);
});

it('replays each key not yet kept once, though it is asked for twice, none where every key is kept, and keeps no replay that had no time', function (): void {
    $remembered = new Remembered();
    $replays = new ReplayRuns(['b']);

    $first = $remembered->replays(['a', 'b', 'a'], $replays->run(...));
    $again = $remembered->replays(['a', 'b'], $replays->run(...));
    $kept = $remembered->replays(['a'], $replays->run(...));

    expect($replays->ran())->toBe([['a', 'b'], ['b']])
        ->and($kept)->toBe([ReplayVerdict::Stands])
        ->and($first)->toBe([ReplayVerdict::Stands, ReplayVerdict::NoTime, ReplayVerdict::Stands])
        ->and($again)->toBe([ReplayVerdict::Stands, ReplayVerdict::NoTime]);
});
