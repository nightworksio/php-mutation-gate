<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\KillControl;
use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\KillControls;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ExactRun;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\RunSelection;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Growth;
use NightWorksIO\MutationGate\Tests\Support\LoadingUnfiltered;

/** A mutant of a file, ended so, killed by these tests, by its native id. */
function killedBy(string $file, string $nativeId, MutantStatus $status, string ...$killers): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of($file), 'LessThan', sprintf("-<\n+%s", $nativeId), 0),
        $nativeId,
        Location::of(Path::of($file), Line::of(3), Line::of(3)),
        Mutation::of('LessThan', MutatorFamily::Boundary, ''),
        $status,
        Unmeasured::duration(),
    )->killedBy(TestIds::of(...array_map(TestId::of(...), $killers)));
}

/** A map that times LoopTest::a at two seconds, and LoopTest::b not at all. */
function killersMap(): CoverageMap
{
    return CoverageMap::of()
        ->covered(Path::of('src/Loop.php'), Line::of(3), TestId::of('LoopTest::a'))
        ->timed(TestId::of('LoopTest::a'), Seconds::of(2.0));
}

/** A selection as `ExactRun`'s, of a runner that replays each kill's own order itself. */
final readonly class ReplayingRun implements RunSelection
{
    public function of(TestIds $judging, CoverageMap $map, Paths|WholeSuite|NotGiven $unfiltered): RunTests
    {
        return new ExactRun()->of($judging, $map, $unfiltered);
    }

    public function replaysKills(): bool
    {
        return true;
    }
}

/** The kill controls of a runner that replays each kill's own order itself. */
function replayedKillControls(Mutants $mutants, LimitBounds $bounds): KillControls
{
    return KillControls::of(
        $mutants,
        Evidences::none(),
        killersMap(),
        HeldCovered::none(),
        new ReplayingRun(),
        $bounds,
    );
}

/** The kill controls of a runner that replays no kill itself, with this evidence. */
function orderedKillControls(Mutants $mutants, Evidences $evidence): KillControls
{
    return KillControls::of($mutants, $evidence, killersMap(), HeldCovered::none(), new ExactRun(), killBounds());
}

/** Evidence of a mutant by its native id, whose run started these tests up to its last killer. */
function orderOf(string $file, string $nativeId, string ...$tests): Evidences
{
    return Evidences::none()->with(
        MutantId::hash(Path::of($file), 'LessThan', sprintf("-<\n+%s", $nativeId), 0),
        Evidence::none()->withPrefix(Prefix::at(1))->takingOrder(TestIds::of(...array_map(TestId::of(...), $tests))),
    );
}

/** Ten seconds at least, a hundred at most. */
function killBounds(): LimitBounds
{
    return LimitBounds::between(Seconds::of(10.0), Seconds::of(100.0));
}

/**
 * Each control asked for, as its file, its tests and its limit.
 *
 * @return list<array{string, list<string>, float}>
 */
function askedKillControls(KillControls $controls): array
{
    return array_map(static fn(Control $control): array => [
        $control->file()->value(),
        array_map(static fn(TestId $test): string => $test->value(), [...$control->tests()]),
        $control->limit()->seconds(),
    ], [...$controls->asked()]);
}

it('asks for one control of each file first: every test that killed one of its mutants, its file, and the standard limit of their time', function (): void {
    $mutants = Mutants::of(
        killedBy('src/Loop.php', '1', MutantStatus::Killed, 'LoopTest::a'),
        killedBy('src/Loop.php', '2', MutantStatus::Killed, 'LoopTest::a'),
        killedBy('src/Loop.php', '3', MutantStatus::Killed, 'LoopTest::b'),
        killedBy('src/Tax.php', '4', MutantStatus::Killed, 'LoopTest::a'),
    );

    expect(askedKillControls(replayedKillControls($mutants, LimitBounds::between(Seconds::of(1.0), Seconds::of(100.0)))))->toBe([
        ['src/Loop.php', ['LoopTest::a', 'LoopTest::b'], 1.0],
        ['src/Tax.php', ['LoopTest::a'], 11.0],
    ]);
});

it('asks for each kill\'s own control only where its file\'s did not pass, ran, and is not that same control', function (): void {
    $mutants = Mutants::of(
        killedBy('src/Loop.php', '1', MutantStatus::Killed, 'LoopTest::a'),
        killedBy('src/Loop.php', '2', MutantStatus::Killed, 'LoopTest::a'),
        killedBy('src/Loop.php', '3', MutantStatus::Killed, 'LoopTest::b'),
        killedBy('src/Tax.php', '4', MutantStatus::Killed, 'LoopTest::a'),
        killedBy('src/Rate.php', '5', MutantStatus::Killed, 'LoopTest::a'),
        killedBy('src/Rate.php', '6', MutantStatus::Killed, 'LoopTest::b'),
    );
    $controls = replayedKillControls($mutants, killBounds());
    [$loop, $tax, $rate] = [...$controls->asked()];
    $runs = ControlRuns::none()->with($loop, ControlRun::failed(TestIds::none()))->with($tax, ControlRun::failed(TestIds::none()));
    $fallback = static fn(KillControls $controls): array => array_map(static fn(Control $control): array => [
        $control->file()->value(),
        array_map(static fn(TestId $test): string => $test->value(), [...$control->tests()]),
    ], [...$controls->fallback($runs, Controls::of($rate))]);

    expect($fallback($controls))->toBe([['src/Loop.php', ['LoopTest::a']], ['src/Loop.php', ['LoopTest::b']]])
        ->and([...$controls->fallback(ControlRuns::none()->with($loop, ControlRun::passed(Seconds::of(1.0))), Controls::of($tax, $rate))])->toBe([]);
});

it('lets every kill of a file stand where its file\'s control passed, and judges each by its own where it did not', function (): void {
    $mutants = Mutants::of(
        killedBy('src/Loop.php', '1', MutantStatus::Killed, 'LoopTest::a'),
        killedBy('src/Loop.php', '2', MutantStatus::Killed, 'LoopTest::b'),
    );
    $controls = replayedKillControls($mutants, killBounds());
    [$file] = [...$controls->asked()];
    [$a, $b] = [...$controls->fallback(ControlRuns::none()->with($file, ControlRun::failed(TestIds::none())), Controls::none())];
    $statuses = static fn(ControlRuns $runs, Controls $left): array => array_map(
        static fn(Mutant $mutant): MutantStatus => $mutant->status(),
        [...$controls->applied($mutants, $runs, $left)],
    );

    expect($statuses(ControlRuns::none()->with($file, ControlRun::passed(Seconds::of(1.0))), Controls::none()))->toBe([MutantStatus::Killed, MutantStatus::Killed])
        ->and($statuses(ControlRuns::none()->with($file, ControlRun::failed(TestIds::none()))->with($a, ControlRun::passed(Seconds::of(1.0)))->with($b, ControlRun::failed(TestIds::none())), Controls::none()))
        ->toBe([MutantStatus::Killed, MutantStatus::FailsUnmutated])
        ->and($statuses(ControlRuns::none(), Controls::of($file)))->toBe([MutantStatus::Unjudged, MutantStatus::Unjudged]);
});

it('asks for no control of a kill no test is named for, nor of a mutant not killed by a test', function (): void {
    $mutants = Mutants::of(
        killedBy('src/Loop.php', 'unnamed', MutantStatus::Killed),
        killedBy('src/Loop.php', 'survived', MutantStatus::Survived),
        killedBy('src/Loop.php', 'analysed', MutantStatus::KilledByStaticAnalysis, 'LoopTest::a'),
        killedBy('src/Loop.php', 'timed out', MutantStatus::TimedOut, 'LoopTest::a'),
    );

    expect(askedKillControls(replayedKillControls($mutants, killBounds())))->toBe([]);
});

it('lets a kill stand where its control passed, and leaves it unjudged, saying why, where it failed, ran out or never ran', function (ControlRun $found, MutantStatus $status, string $reason): void {
    $mutants = Mutants::of(killedBy('src/Loop.php', '1', MutantStatus::Killed, 'LoopTest::a'));
    $controls = replayedKillControls($mutants, killBounds());
    $applied = [...$controls->applied($mutants, ControlRuns::none()->with([...$controls->asked()][0], $found), Controls::none())];
    $said = $applied[0]->reason();

    expect([$applied[0]->status(), $said instanceof Reason ? $said->text() : ''])->toBe([$status, $reason]);
})->with([
    'tests that pass unmutated' => [fn(): ControlRun => ControlRun::passed(Seconds::of(2.0)), MutantStatus::Killed, ''],
    'tests that fail unmutated' => [fn(): ControlRun => ControlRun::failed(TestIds::none()), MutantStatus::FailsUnmutated, KillControls::FAILS_UNMUTATED],
    'tests out of memory unmutated' => [fn(): ControlRun => ControlRun::outOfMemory(), MutantStatus::Unjudged, KillControls::OUT_OF_MEMORY],
    'tests that run out unmutated' => [fn(): ControlRun => ControlRun::ranOut(), MutantStatus::Unjudged, KillControls::RAN_OUT],
    'a control never run' => [fn(): ControlRun => ControlRun::unrun('the file is gone'), MutantStatus::Unjudged, fn(): string => sprintf(KillControls::UNRUN, 'the file is gone')],
]);

it('says a control the runs hold nothing for never ran, and leaves a kill whose control was left unjudged by the budget', function (): void {
    $mutants = Mutants::of(
        killedBy('src/Loop.php', '1', MutantStatus::Killed, 'LoopTest::a'),
        killedBy('src/Tax.php', '2', MutantStatus::Killed, 'LoopTest::a'),
    );
    $controls = replayedKillControls($mutants, killBounds());
    $applied = [...$controls->applied($mutants, ControlRuns::none(), Controls::of([...$controls->asked()][1]))];

    expect($applied[0]->reason())->toEqual(Reason::that(sprintf(KillControls::UNRUN, ControlRuns::NOT_RUN)))
        ->and($applied[1]->reason())->toEqual(OutOfTime::BeforeControlling->reason());
});

it('asks for and applies kill controls in time linear in the number of mutants', function (): void {
    $timing = static function (int $size): Closure {
        $mutants = Mutants::of(...array_map(
            static fn(int $at): Mutant => killedBy('src/Loop.php', strval($at), MutantStatus::Killed, 'LoopTest::a'),
            range(1, $size),
        ));

        return static fn(): int => count(replayedKillControls($mutants, killBounds())
            ->applied($mutants, ControlRuns::none(), Controls::none()));
    };

    expect($timing(10)())->toBe(10)
        ->and(Growth::of(1000, $timing))->toBeLessThan(Growth::LINEAR);
});

it('names, for a kill whose tests fail without it, the tests its control recorded failing, or every killer where it recorded none', function (TestIds $recorded, array $failing): void {
    $mutants = Mutants::of(killedBy('src/Loop.php', '1', MutantStatus::Killed, 'LoopTest::a'));
    $controls = replayedKillControls($mutants, killBounds());
    $applied = [...$controls->applied($mutants, ControlRuns::none()->with([...$controls->asked()][0], ControlRun::failed($recorded)), Controls::none())];

    expect($applied[0]->status())->toBe(MutantStatus::FailsUnmutated)
        ->and(array_map(static fn(TestId $test): string => $test->value(), [...$applied[0]->killers()]))->toBe($failing);
})->with([
    'recorded' => [fn(): TestIds => TestIds::of(TestId::of('LoopTest::a#2')), ['LoopTest::a#2']],
    'none recorded' => [fn(): TestIds => TestIds::none(), ['LoopTest::a']],
]);

it('controls a kill whose run left its filter out by the test files that run loaded, its limit theirs, not by the file\'s other killers', function (): void {
    $map = CoverageMap::of()
        ->covered(Path::of('src/Loop.php'), Line::of(3), TestId::of('LoopTest::a'))
        ->timed(TestId::of('LoopTest::a'), Seconds::of(2.0))
        ->timed(TestId::of('LoopTest::b'), Seconds::of(30.0));
    $wide = killedBy('src/Loop.php', '1', MutantStatus::Killed, 'LoopTest::a', 'LoopTest::b');
    $mutants = Mutants::of($wide, killedBy('src/Loop.php', '2', MutantStatus::Killed, 'LoopTest::a'));
    $loaded = Paths::of(Path::of('tests/LoopTest.php'));
    $controls = KillControls::of(
        $mutants,
        Evidences::none()->with($wide->id(), Evidence::none()->withPrefix(Prefix::at(1))->loadingUnfiltered($loaded)),
        $map,
        HeldCovered::none(),
        new LoadingUnfiltered(),
        killBounds(),
        KillControl::Alone,
    );
    [$files, $alone] = [...$controls->asked()];
    $failed = ControlRuns::none()->with($files, ControlRun::failed(TestIds::none()))->with($alone, ControlRun::passed(Seconds::of(1.0)));

    expect($files->selected())->toEqual($loaded)
        ->and($files->limit()->seconds())->toBeGreaterThan(90.0)
        ->and($alone->selected())->toEqual(NotGiven::value())
        ->and(array_map(static fn(TestId $test): string => $test->value(), [...$alone->tests()]))->toBe(['LoopTest::a'])
        ->and([...$controls->fallback($failed, Controls::none())])->toBe([]);
});

it('controls a file\'s kills whose runs left their filter out together first, by every test file those runs loaded, each on its own where that fails, and one that ran every test file by itself', function (): void {
    $map = CoverageMap::of()
        ->covered(Path::of('src/Loop.php'), Line::of(3), TestId::of('LoopTest::a'))
        ->timed(TestId::of('LoopTest::a'), Seconds::of(2.0))
        ->timed(TestId::of('RateTest::b'), Seconds::of(3.0));
    $one = killedBy('src/Loop.php', '1', MutantStatus::Killed, 'LoopTest::a');
    $two = killedBy('src/Loop.php', '2', MutantStatus::Killed, 'RateTest::b');
    $other = killedBy('src/Rate.php', '3', MutantStatus::Killed, 'RateTest::b');
    $whole = killedBy('src/Tax.php', '4', MutantStatus::Killed, 'LoopTest::a');
    $named = killedBy('src/Tax.php', '5', MutantStatus::Killed, 'RateTest::b');
    $loading = static fn(string ...$files): Evidence => Evidence::none()
        ->withPrefix(Prefix::at(1))
        ->loadingUnfiltered(Paths::of(...array_map(Path::of(...), $files)));
    $controls = KillControls::of(
        Mutants::of($one, $two, $other, $whole, $named),
        Evidences::none()
            ->with($one->id(), $loading('tests/LoopTest.php'))
            ->with($two->id(), $loading('tests/RateTest.php'))
            ->with($other->id(), $loading('tests/RateTest.php'))
            ->with($whole->id(), Evidence::none()->withPrefix(Prefix::at(1))->loadingUnfiltered(WholeSuite::tests())),
        $map,
        HeldCovered::none(),
        new LoadingUnfiltered(),
        killBounds(),
        KillControl::Alone,
    );
    [$loop, , $tax] = [...$controls->asked()];
    $failed = ControlRuns::none()->with($loop, ControlRun::failed(TestIds::none()));

    expect(askedKillControls($controls))->toBe([
        ['src/Loop.php', ['LoopTest::a', 'RateTest::b'], 20.0],
        ['src/Rate.php', ['RateTest::b'], 14.0],
        ['src/Tax.php', ['LoopTest::a'], 11.0],
        ['src/Tax.php', ['RateTest::b'], 14.0],
    ])
        ->and($tax->selected())->toEqual(WholeSuite::tests())
        ->and($loop->selected())->toEqual(Paths::of(Path::of('tests/LoopTest.php'), Path::of('tests/RateTest.php')))
        ->and(array_map(static fn(Control $own) => $own->selected() instanceof Paths ? $own->selected() : null, [...$controls->fallback($failed, Controls::none())]))
        ->toEqual([Paths::of(Path::of('tests/LoopTest.php')), Paths::of(Path::of('tests/RateTest.php'))])
        ->and([...$controls->fallback(ControlRuns::none()->with($loop, ControlRun::passed(Seconds::of(1.0))), Controls::none())])->toBe([]);
});

it('replays the tests each kill\'s run started up to its last killer, one run vouching for every shorter order of its file', function (): void {
    $mutants = Mutants::of(
        killedBy('src/Loop.php', '1', MutantStatus::Killed, 'LoopTest::b'),
        killedBy('src/Loop.php', '2', MutantStatus::Killed, 'LoopTest::c'),
        killedBy('src/Loop.php', '3', MutantStatus::Killed, 'LoopTest::d'),
    );
    $evidence = orderOf('src/Loop.php', '1', 'LoopTest::a', 'LoopTest::b')
        ->and(orderOf('src/Loop.php', '2', 'LoopTest::a', 'LoopTest::b', 'LoopTest::c'))
        ->and(orderOf('src/Loop.php', '3', 'LoopTest::x', 'LoopTest::d'));
    $controls = orderedKillControls($mutants, $evidence);
    $tests = static fn(Controls $asked): array => array_map(
        static fn(Control $control): array => array_map(static fn(TestId $test): string => $test->value(), [...$control->tests()]),
        [...$asked],
    );
    [$long, $other] = [...$controls->asked()];
    $failed = ControlRuns::none()->with($long, ControlRun::failed(TestIds::none()))->with($other, ControlRun::passed(Seconds::of(1.0)));

    expect($tests($controls->asked()))->toBe([
        ['LoopTest::a', 'LoopTest::b', 'LoopTest::c'],
        ['LoopTest::x', 'LoopTest::d'],
    ])
        ->and($tests($controls->fallback($failed, Controls::none())))->toBe([['LoopTest::a', 'LoopTest::b']]);
});

it('replays all a kill\'s run selected where the runner recorded no order, and only its own run vouches for it', function (): void {
    $map = CoverageMap::of()
        ->covered(Path::of('src/Loop.php'), Line::of(3), TestId::of('LoopTest::a'))
        ->covered(Path::of('src/Loop.php'), Line::of(3), TestId::of('LoopTest::b'))
        ->covered(Path::of('src/Loop.php'), Line::of(3), TestId::of('LoopTest::c'));
    $controls = KillControls::of(
        Mutants::of(killedBy('src/Loop.php', '1', MutantStatus::Killed, 'LoopTest::b')),
        Evidences::none(),
        $map,
        HeldCovered::none(),
        new ExactRun(),
        killBounds(),
    );
    [$only] = [...$controls->asked()];

    expect(array_map(static fn(TestId $test): string => $test->value(), [...$only->tests()]))
        ->toBe(['LoopTest::a', 'LoopTest::b', 'LoopTest::c'])
        ->and([...$controls->fallback(ControlRuns::none()->with($only, ControlRun::failed(TestIds::none())), Controls::none())])
        ->toBe([]);
});

it('runs the killers alone under flaky.killControls alone, one control of a file vouching for them all', function (): void {
    $mutants = Mutants::of(
        killedBy('src/Loop.php', '1', MutantStatus::Killed, 'LoopTest::b'),
        killedBy('src/Loop.php', '2', MutantStatus::Killed, 'LoopTest::c'),
    );
    $controls = KillControls::of(
        $mutants,
        orderOf('src/Loop.php', '1', 'LoopTest::a', 'LoopTest::b'),
        killersMap(),
        HeldCovered::none(),
        new ExactRun(),
        killBounds(),
        KillControl::Alone,
    );

    expect(array_column(askedKillControls($controls), 1))->toBe([['LoopTest::b', 'LoopTest::c']]);
});

it('controls a kill whose run left its filter out by its killers under until-killer, where the runner replayed its order', function (): void {
    $wide = killedBy('src/Loop.php', '1', MutantStatus::Killed, 'LoopTest::a');
    $evidence = Evidences::none()->with(
        $wide->id(),
        Evidence::none()->withPrefix(Prefix::at(1))->loadingUnfiltered(Paths::of(Path::of('tests/LoopTest.php'))),
    );
    $controlled = static fn(LongFilter $long): mixed => [...KillControls::of(
        Mutants::of($wide),
        $evidence,
        killersMap(),
        HeldCovered::none(),
        new ReplayingRun(),
        killBounds(),
        KillControl::Order,
        $long,
    )->asked()][0]->selected();

    expect($controlled(LongFilter::UntilKiller))->toEqual(NotGiven::value())
        ->and($controlled(LongFilter::Files))->toEqual(Paths::of(Path::of('tests/LoopTest.php')));
});
