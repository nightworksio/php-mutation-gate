<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\Shares;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

$at = Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z'));

$measured = Measurement::of(Seconds::of(60.0), 'pest', $at);

$mutant = static fn(string $file, int $line, Seconds|Unmeasured $duration): Mutant => Mutant::of(
    MutantId::hash(Path::of($file), 'Plus', sprintf('-%d', $line), 0),
    sprintf('%d', $line),
    Location::of(Path::of($file), Line::of($line), Line::of($line)),
    Mutation::of('Plus', MutatorFamily::Arithmetic, sprintf('-%d', $line)),
    MutantStatus::Killed,
    $duration,
);

$timing = static fn(string $unit, float $seconds): Timing => Timing::of(
    Path::of($unit),
    Seconds::of($seconds),
    'pest',
    $at,
);

it('shares the time a shard spent among its units by their mutants\' durations', function () use (
    $measured,
    $mutant,
    $timing,
): void {
    $units = Units::of(Unit::file(Path::of('src/A.php')), Unit::file(Path::of('src/B.php')));
    $mutants = Mutants::of(
        $mutant('src/A.php', 1, Seconds::of(1.0)),
        $mutant('src/B.php', 1, Seconds::of(1.0)),
        $mutant('src/A.php', 2, Seconds::of(2.0)),
    );

    expect(Shares::of($units, $mutants, CoverageMap::empty(), $measured))
        ->toEqual(Timings::of($timing('src/A.php', 45.0), $timing('src/B.php', 15.0)));
});

it('shares by proportion however short the durations', function () use ($measured, $mutant, $timing): void {
    $units = Units::of(Unit::file(Path::of('src/A.php')), Unit::file(Path::of('src/B.php')));
    $mutants = Mutants::of($mutant('src/A.php', 1, Seconds::of(0.25)), $mutant('src/B.php', 1, Seconds::of(0.75)));

    expect(Shares::of($units, $mutants, CoverageMap::empty(), $measured))
        ->toEqual(Timings::of($timing('src/A.php', 15.0), $timing('src/B.php', 45.0)));
});

it('stands in for a mutant with no duration with the time the tests covering its line took', function () use (
    $measured,
    $mutant,
    $timing,
): void {
    $coverage = CoverageMap::empty()
        ->covered(Path::of('src/A.php'), Line::of(3), TestId::of('ATest::first'))
        ->covered(Path::of('src/A.php'), Line::of(3), TestId::of('ATest::second'))
        ->covered(Path::of('src/B.php'), Line::of(5), TestId::of('BTest::timed'))
        ->covered(Path::of('src/B.php'), Line::of(5), TestId::of('BTest::untimed'))
        ->covered(Path::of('src/B.php'), Line::of(9), TestId::of('BTest::elsewhere'))
        ->timed(TestId::of('ATest::first'), Seconds::of(2.0))
        ->timed(TestId::of('ATest::second'), Seconds::of(1.0))
        ->timed(TestId::of('BTest::timed'), Seconds::of(1.0))
        ->timed(TestId::of('BTest::elsewhere'), Seconds::of(7.0));
    $units = Units::of(Unit::file(Path::of('src/A.php')), Unit::file(Path::of('src/B.php')));
    $mutants = Mutants::of(
        $mutant('src/A.php', 3, Unmeasured::duration()),
        $mutant('src/B.php', 5, Unmeasured::duration()),
    );

    expect(Shares::of($units, $mutants, $coverage, $measured))
        ->toEqual(Timings::of($timing('src/A.php', 45.0), $timing('src/B.php', 15.0)));
});

it('shares the time equally where nothing was timed', function () use ($measured, $mutant, $timing): void {
    $units = Units::of(Unit::file(Path::of('src/A.php')), Unit::file(Path::of('src/B.php')));
    $mutants = Mutants::of($mutant('src/A.php', 1, Unmeasured::duration()));

    expect(Shares::of($units, $mutants, CoverageMap::empty(), $measured))
        ->toEqual(Timings::of($timing('src/A.php', 30.0), $timing('src/B.php', 30.0)));
});

it('counts a mutant in a file under a held path for that path, and no file that only starts like it', function () use (
    $measured,
    $mutant,
    $timing,
): void {
    $units = Units::of(
        Unit::held(Path::of('src/Domain'), Group::named('holds:domain')),
        Unit::file(Path::of('src/DomainX.php')),
    );
    $mutants = Mutants::of(
        $mutant('src/Domain/Money.php', 1, Seconds::of(1.0)),
        $mutant('src/DomainX.php', 1, Seconds::of(3.0)),
    );

    expect(Shares::of($units, $mutants, CoverageMap::empty(), $measured))
        ->toEqual(Timings::of($timing('src/Domain', 15.0), $timing('src/DomainX.php', 45.0)));
});

it('learns nothing from a shard with no units', function () use ($measured): void {
    expect(Shares::of(Units::none(), Mutants::none(), CoverageMap::empty(), $measured))->toEqual(Timings::none());
});
