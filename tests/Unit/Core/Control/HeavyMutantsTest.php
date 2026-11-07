<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\HeavyMutants;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Runner\ExactRun;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** A mutant of a file on line 3, ended so at this limit. */
function heavyMutant(string $file, MutantStatus $status, float $limit): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of($file), 'LessThan', "-<\n+<=", 0),
        $file,
        Location::of(Path::of($file), Line::of(3), Line::of(3)),
        Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<="),
        $status,
        Seconds::of($limit),
    )->withLimit(Seconds::of($limit));
}

/** A map in which tests a, b and c of 1.5 seconds each cover line 3 of each of these files, and test d alone line 3 of src/Light.php. */
function heavyMap(): CoverageMap
{
    $map = CoverageMap::of();

    foreach (['src/Heavy.php', 'src/Gone.php', 'src/Slow.php'] as $file) {
        $map = $map->covered(Path::of($file), Line::of(3), TestId::of('a'), TestId::of('b'), TestId::of('c'));
    }

    $map = $map->covered(Path::of('src/Light.php'), Line::of(3), TestId::of('d'));

    foreach (['a', 'b', 'c', 'd'] as $test) {
        $map = $map->timed(TestId::of($test), Seconds::of(1.5));
    }

    return $map;
}

/** The heavy mutants among these, under a most of six seconds, src/Gone.php's text not to be had. */
function heavyAmong(Mutants $mutants): HeavyMutants
{
    return HeavyMutants::of(
        $mutants,
        heavyMap(),
        HeldCovered::none(),
        new ExactRun(),
        LimitBounds::between(Seconds::of(1.0), Seconds::of(6.0))->startingIn(Seconds::of(0.1)),
        static fn(Mutant $mutant): Contents|CannotJudge => $mutant->location()->file()->value() === 'src/Gone.php'
            ? CannotJudge::because('gone')
            : Contents::of('<?php // mutant'),
        static fn(): TestPlaces => TestPlaces::none(),
    );
}

it('parts a mutant that timed out at the most whose tests run past it only together, and leaves the rest to their controls', function (): void {
    $heavy = heavyMutant('src/Heavy.php', MutantStatus::TimedOut, 6.0);
    $mutants = Mutants::of(
        $heavy,
        heavyMutant('src/Gone.php', MutantStatus::TimedOut, 6.0),
        heavyMutant('src/Slow.php', MutantStatus::TimedOut, 5.0),
        heavyMutant('src/Light.php', MutantStatus::TimedOut, 6.0),
        heavyMutant('src/Heavy.php', MutantStatus::Survived, 6.0)->identifiedAs(MutantId::hash(Path::of('src/Heavy.php'), 'Other', '', 0)),
    );
    $parted = heavyAmong($mutants);
    $asked = [...$parted->asked()];

    expect(array_map(static fn(Mutant $mutant): string => $mutant->location()->file()->value(), [...$parted->without($mutants)]))
        ->toBe(['src/Gone.php', 'src/Slow.php', 'src/Light.php', 'src/Heavy.php'])
        ->and(array_map(static fn(Control $control): array => [
            array_map(static fn(TestId $test): string => $test->value(), [...$control->tests()]),
            $control->limit(),
            $control->served(),
            $control->silence(),
        ], $asked))->toEqual([[['a'], Seconds::of(4.8), Contents::of('<?php // mutant'), Seconds::of(4.8)]]);
});

it('judges each heavy mutant part after part, and unjudges one whose run the time left had no room for', function (): void {
    $heavy = heavyMutant('src/Heavy.php', MutantStatus::TimedOut, 6.0);
    $parted = heavyAmong(Mutants::of($heavy));
    $first = [...$parted->asked()][0];
    $parted = $parted->after(ControlRuns::none()->with($first, ControlRun::passed(Seconds::of(1.5))), Controls::none());
    $second = [...$parted->asked()][0];
    $killed = $parted->after(ControlRuns::none()->with($second, ControlRun::failed(TestIds::of(TestId::of('b')))), Controls::none());
    $left = $parted->after(ControlRuns::none(), Controls::of($second));

    expect([...$second->tests()])->toEqual([TestId::of('b')])
        ->and(count($killed->asked()))->toBe(0)
        ->and(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), [...$killed->applied(Mutants::of($heavy))]))
        ->toBe([MutantStatus::Killed])
        ->and(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), [...$left->applied(Mutants::of($heavy))]))
        ->toBe([MutantStatus::Unjudged]);
});

/**
 * What each of a pool's rounds asks of heavy mutants, by each run's test, until each is judged, and how they end.
 *
 * @param  Closure(Control): ControlRun            $finds what each run finds
 * @return array{list<list<string>>, HeavyMutants}
 */
function heavyRounds(HeavyMutants $parted, ProcessCount $pool, Closure $finds): array
{
    $rounds = [];

    for ($asked = [...$parted->asked()]; $asked !== []; $asked = [...$parted->asked()]) {
        $round = [...$asked, ...$parted->ahead($pool)];
        $rounds[] = array_map(static fn(Control $control): string => [...$control->tests()][0]->value(), $round);
        $runs = ControlRuns::none();

        foreach ($round as $control) {
            $runs = $runs->with($control, $finds($control));
        }

        $parted = $parted->after($runs, Controls::none());
    }

    return [$rounds, $parted];
}

it('fills a round\'s pool after what each mutant waits on, each mutant\'s next part ahead in turn, and keeps what they found', function (): void {
    $mutants = Mutants::of(
        heavyMutant('src/Heavy.php', MutantStatus::TimedOut, 6.0),
        heavyMutant('src/Slow.php', MutantStatus::TimedOut, 6.0),
    );
    $parted = heavyAmong($mutants);
    $passed = static fn(): ControlRun => ControlRun::passed(Seconds::of(1.5));

    [$narrow] = heavyRounds($parted, ProcessCount::single(), $passed);
    [$rounds, $judged] = heavyRounds($parted, ProcessCount::of(5), $passed);

    expect($narrow)->toBe([['a', 'a'], ['b', 'b'], ['c', 'c']])
        ->and($rounds)->toBe([['a', 'a', 'b', 'c', 'b'], ['c']])
        ->and(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), [...$judged->applied($mutants)]))
        ->toBe([MutantStatus::Survived, MutantStatus::Survived]);
});

it('judges a mutant the same in a pool of any width, and runs no part past one found to kill', function (ProcessCount $slots, array $rounds): void {
    $mutants = Mutants::of(heavyMutant('src/Heavy.php', MutantStatus::TimedOut, 6.0));
    $finds = static fn(Control $control): ControlRun => [...$control->tests()][0]->value() === 'b'
        ? ControlRun::failed(TestIds::of(TestId::of('b')))
        : ControlRun::passed(Seconds::of(1.5));
    [$asked, $judged] = heavyRounds(heavyAmong($mutants), $slots, $finds);
    $mutant = [...$judged->applied($mutants)][0];

    expect($asked)->toBe($rounds)
        ->and($mutant->status())->toBe(MutantStatus::Killed)
        ->and(array_map(static fn(TestId $test): string => $test->value(), [...$mutant->killers()]))->toBe(['b']);
})->with([
    'one at a time' => [fn(): ProcessCount => ProcessCount::single(), [['a'], ['b']]],
    'two at a time' => [fn(): ProcessCount => ProcessCount::of(2), [['a', 'b']]],
    'room for all' => [fn(): ProcessCount => ProcessCount::of(8), [['a', 'b', 'c']]],
]);

it('unjudges a mutant whose next the time left had no room for, and asks again for a part ahead it had none for', function (): void {
    $mutants = Mutants::of(heavyMutant('src/Heavy.php', MutantStatus::TimedOut, 6.0));
    $parted = heavyAmong($mutants);
    $first = [...$parted->asked()][0];
    [$second, $third] = $parted->ahead(ProcessCount::of(3));
    $outOfTime = $parted->after(ControlRuns::none(), Controls::of($first, $second, $third));
    $aheadLeft = $parted->after(ControlRuns::none()->with($first, ControlRun::passed(Seconds::of(1.5))), Controls::of($second, $third));

    expect(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), [...$outOfTime->applied($mutants)]))
        ->toBe([MutantStatus::Unjudged])
        ->and([...$aheadLeft->asked()])->toEqual([$second])
        ->and(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), [...$aheadLeft->applied($mutants)]))
        ->toBe([MutantStatus::TimedOut]);
});

it('fills nothing where what mutants wait on takes the pool, and says what runs ahead in the log', function (): void {
    $parted = heavyAmong(Mutants::of(
        heavyMutant('src/Heavy.php', MutantStatus::TimedOut, 6.0),
        heavyMutant('src/Slow.php', MutantStatus::TimedOut, 6.0),
    ));

    expect($parted->ahead(ProcessCount::of(2)))->toBe([])
        ->and($parted->ahead(ProcessCount::single()))->toBe([])
        ->and($parted->progress(ProcessCount::of(3))[0])->toContain('1 ahead beside it')
        ->and($parted->progress(ProcessCount::of(3))[1])->not->toContain('ahead');
});

it('holds every mutant to the parts it is given, and none where it is given none', function (): void {
    $mutants = Mutants::of(heavyMutant('src/Heavy.php', MutantStatus::TimedOut, 6.0));
    $passed = static fn(): ControlRun => ControlRun::passed(Seconds::of(1.5));
    [$rounds, $capped] = heavyRounds(heavyAmong($mutants)->judgedByAtMost(2), ProcessCount::of(8), $passed);
    [$all, $whole] = heavyRounds(heavyAmong($mutants)->judgedByAtMost(NotGiven::value()), ProcessCount::of(8), $passed);

    expect($rounds)->toBe([['a', 'b']])
        ->and(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), [...$capped->applied($mutants)]))->toBe([MutantStatus::Unjudged])
        ->and($all)->toBe([['a', 'b', 'c']])
        ->and(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), [...$whole->applied($mutants)]))->toBe([MutantStatus::Survived]);
});

it('asks again for a part ahead that was never run, and judges it by what it finds in its turn', function (): void {
    $mutants = Mutants::of(heavyMutant('src/Heavy.php', MutantStatus::TimedOut, 6.0));
    $parted = heavyAmong($mutants);
    $first = [...$parted->asked()][0];
    [$second] = $parted->ahead(ProcessCount::of(2));
    $after = $parted->after(
        ControlRuns::none()->with($first, ControlRun::passed(Seconds::of(1.5)))->with($second, ControlRun::unrun('no room')),
        Controls::none(),
    );

    expect([...$after->asked()])->toEqual([$second])
        ->and(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), [...$after->applied($mutants)]))
        ->toBe([MutantStatus::TimedOut]);
});
