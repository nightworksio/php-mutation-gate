<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\MemoryControls;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\Covered;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Support\Growth;

/** A mutant of a file at line 3, ended so, by its native id. */
function memoryMutant(string $file, string $nativeId, MutantStatus $status): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of($file), 'Increment', sprintf("-<\n+%s", $nativeId), 0),
        $nativeId,
        Location::of(Path::of($file), Line::of(3), Line::of(3)),
        Mutation::of('Increment', MutatorFamily::Arithmetic, ''),
        $status,
        Unmeasured::duration(),
    );
}

/** A mutant of `src/Grow.php` out of a cap of 64 megabytes. */
function grownMutant(string $nativeId = '1'): Mutant
{
    return memoryMutant('src/Grow.php', $nativeId, MutantStatus::OutOfMemory)->withLimit(MemoryCap::of(64, MemoryUnit::Megabytes));
}

/** A map in which GrowTest::a, timed at two seconds, covers line 3 of `src/Grow.php`. */
function growMap(): CoverageMap
{
    return CoverageMap::empty()
        ->covered(Path::of('src/Grow.php'), Line::of(3), TestId::of('GrowTest::a'))
        ->timed(TestId::of('GrowTest::a'), Seconds::of(2.0));
}

/** Ten seconds at least, a hundred at most. */
function memoryBounds(): LimitBounds
{
    return LimitBounds::between(Seconds::of(10.0), Seconds::of(100.0));
}

/**
 * Each control asked for, as its file, its tests and its limit.
 *
 * @return list<array{string, list<string>, float}>
 */
function askedMemoryControls(MemoryControls $controls): array
{
    return array_map(static fn(Control $control): array => [
        $control->file()->value(),
        array_map(static fn(TestId $test): string => $test->value(), [...$control->tests()]),
        $control->limit()->seconds(),
    ], [...$controls->asked()]);
}

it('asks for one control of each mutant out of a memory cap: its covering tests, its file, and the standard limit of their time', function (): void {
    $mutants = Mutants::of(grownMutant('1'), grownMutant('2'));

    expect(askedMemoryControls(MemoryControls::of($mutants, growMap(), HeldCovered::none(), memoryBounds())))
        ->toBe([['src/Grow.php', ['GrowTest::a'], 11.0]]);
});

it('asks for no control of a mutant that did not run out of memory, of one with no cap, or of one no test covers', function (): void {
    $mutants = Mutants::of(
        memoryMutant('src/Grow.php', 'killed', MutantStatus::Killed)->withLimit(MemoryCap::of(64, MemoryUnit::Megabytes)),
        memoryMutant('src/Grow.php', 'no cap', MutantStatus::OutOfMemory),
        memoryMutant('src/Other.php', 'uncovered', MutantStatus::OutOfMemory)->withLimit(MemoryCap::of(64, MemoryUnit::Megabytes)),
    );

    expect(askedMemoryControls(MemoryControls::of($mutants, growMap(), HeldCovered::none(), memoryBounds())))->toBe([]);
});

it('controls a mutant of a held unit by the tests covering its line that hold it', function (): void {
    $map = growMap()->covered(Path::of('src/Grow.php'), Line::of(3), TestId::of('SuiteTest::b'));
    $held = HeldCovered::of(Covered::by(
        Unit::held(Path::of('src/Grow.php'), Group::named('holds:src/Grow.php')),
        TestIds::of(TestId::of('GrowTest::a')),
    ));

    expect(askedMemoryControls(MemoryControls::of(Mutants::of(grownMutant()), $map, $held, memoryBounds())))
        ->toBe([['src/Grow.php', ['GrowTest::a'], 11.0]]);
});

it('judges each mutant out of memory by what its control found', function (ControlRun $found, MutantStatus $status, MemoryCap|Unmeasured $need, string $reason): void {
    $mutants = Mutants::of(grownMutant());
    $controls = MemoryControls::of($mutants, growMap(), HeldCovered::none(), memoryBounds());
    $applied = [...$controls->applied($mutants, ControlRuns::none()->with([...$controls->asked()][0], $found), Controls::none())];
    $said = $applied[0]->reason();

    expect([$applied[0]->status(), $applied[0]->unmutatedNeed(), $said instanceof Reason ? $said->text() : ''])
        ->toEqual([$status, $need, $reason]);
})->with([
    'a control that held 20M' => [
        ControlRun::passed(Seconds::of(1.0))->withPeak(MemoryCap::of(20, MemoryUnit::Megabytes)),
        MutantStatus::OutOfMemory,
        MemoryCap::of(20, MemoryUnit::Megabytes),
        '',
    ],
    'a control that passed, its peak unmeasured' => [ControlRun::passed(Seconds::of(1.0)), MutantStatus::OutOfMemory, MemoryCap::of(64, MemoryUnit::Megabytes), ''],
    'a control that failed' => [ControlRun::failed(), MutantStatus::Unjudged, Unmeasured::duration(), MemoryControls::FAILS_UNMUTATED],
    'a control out of memory too' => [ControlRun::outOfMemory(), MutantStatus::OutOfMemory, Unmeasured::duration(), MemoryControls::OUT_OF_MEMORY],
    'a control out of time' => [ControlRun::ranOut(), MutantStatus::OutOfMemory, Unmeasured::duration(), MemoryControls::RAN_OUT],
    'a control never run' => [
        ControlRun::unrun('the file is gone'),
        MutantStatus::OutOfMemory,
        Unmeasured::duration(),
        sprintf(MemoryControls::UNRUN, 'the file is gone'),
    ],
]);

it('says a control the runs hold nothing for never ran, and leaves one whose control was left unjudged by the budget', function (): void {
    $mutants = Mutants::of(grownMutant('1'), memoryMutant('src/Grow.php', '2', MutantStatus::OutOfMemory)->withLimit(MemoryCap::of(64, MemoryUnit::Megabytes)));
    $controls = MemoryControls::of($mutants, growMap(), HeldCovered::none(), memoryBounds());
    $none = [...$controls->applied($mutants, ControlRuns::none(), Controls::none())];
    $left = [...$controls->applied($mutants, ControlRuns::none(), $controls->asked())];

    expect($none[0]->reason())->toEqual(Reason::that(sprintf(MemoryControls::UNRUN, ControlRuns::NOT_RUN)))
        ->and($left[1]->reason())->toEqual(OutOfTime::BeforeControlling->reason());
});

it('asks for and applies memory controls in time linear in the number of mutants', function (): void {
    $timing = static function (int $size): Closure {
        $mutants = Mutants::of(...array_map(static fn(int $at): Mutant => grownMutant(strval($at)), range(1, $size)));

        return static fn(): int => count(MemoryControls::of($mutants, growMap(), HeldCovered::none(), memoryBounds())
            ->applied($mutants, ControlRuns::none(), Controls::none()));
    };

    expect($timing(10)())->toBe(10)
        ->and(Growth::of(1000, $timing))->toBeLessThan(Growth::LINEAR);
});
