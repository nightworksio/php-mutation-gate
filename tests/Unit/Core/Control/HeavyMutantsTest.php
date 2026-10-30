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
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Runner\ExactRun;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
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
    $map = CoverageMap::empty();

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
