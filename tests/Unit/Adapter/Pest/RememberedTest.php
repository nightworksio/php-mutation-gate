<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Remembered;
use NightWorksIO\MutationGate\Adapter\Pest\ReplayVerdict;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;

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

it('runs each baseline once, all it does not know in one call, and keeps nothing of one stopped or never started', function (): void {
    $remembered = new Remembered();
    $asked = [];
    $running = static function (Ran ...$ends) use (&$asked): Closure {
        return static function (array $keys) use ($ends, &$asked): ProcessEnds {
            $asked[] = $keys;

            return ProcessEnds::of(...$ends);
        };
    };

    $first = $remembered->baselines(
        ['passes', 'fails', 'passes', 'stopped', 'unstarted'],
        $running(Ran::finished(succeeded: true, output: ''), Ran::finished(succeeded: false, output: ''), Ran::stopped('')),
    );
    $again = $remembered->baselines(
        ['fails', 'stopped', 'passes', 'unstarted'],
        $running(Ran::finished(succeeded: true, output: ''), Ran::finished(succeeded: true, output: '')),
    );
    $known = $remembered->baselines(['passes'], $running());

    expect([$first, $again, $known])->toBe([
        [true, false, true, false, false],
        [false, true, true, true],
        [true],
    ])
        ->and($asked)->toBe([['passes', 'fails', 'stopped', 'unstarted'], ['stopped', 'unstarted']]);
});

it('replays each key not yet kept once, though it is asked for twice, none where every key is kept, and keeps no replay that had no time', function (): void {
    $remembered = new Remembered();
    $ran = [];
    $running = static function (array $keys) use (&$ran): array {
        $ran[] = $keys;

        return array_map(static fn(string $key): ReplayVerdict => $key === 'b' ? ReplayVerdict::NoTime : ReplayVerdict::Stands, $keys);
    };

    $first = $remembered->replays(['a', 'b', 'a'], $running);
    $again = $remembered->replays(['a', 'b'], $running);
    $kept = $remembered->replays(['a'], $running);

    expect($ran)->toBe([['a', 'b'], ['b']])
        ->and($kept)->toBe([ReplayVerdict::Stands])
        ->and($first)->toBe([ReplayVerdict::Stands, ReplayVerdict::NoTime, ReplayVerdict::Stands])
        ->and($again)->toBe([ReplayVerdict::Stands, ReplayVerdict::NoTime]);
});
