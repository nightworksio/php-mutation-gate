<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\KillControls;
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
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Growth;

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
    return CoverageMap::empty()
        ->covered(Path::of('src/Loop.php'), Line::of(3), TestId::of('LoopTest::a'))
        ->timed(TestId::of('LoopTest::a'), Seconds::of(2.0));
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

it('asks for one control of each kill a test is named for: its killers, its file, and the standard limit of their time', function (): void {
    $mutants = Mutants::of(
        killedBy('src/Loop.php', '1', MutantStatus::Killed, 'LoopTest::a'),
        killedBy('src/Loop.php', '2', MutantStatus::Killed, 'LoopTest::a'),
        killedBy('src/Loop.php', '3', MutantStatus::Killed, 'LoopTest::b'),
        killedBy('src/Tax.php', '4', MutantStatus::Killed, 'LoopTest::a'),
    );

    expect(askedKillControls(KillControls::of($mutants, killersMap(), LimitBounds::between(Seconds::of(1.0), Seconds::of(100.0)))))->toBe([
        ['src/Loop.php', ['LoopTest::a'], 11.0],
        ['src/Loop.php', ['LoopTest::b'], 1.0],
        ['src/Tax.php', ['LoopTest::a'], 11.0],
    ]);
});

it('asks for no control of a kill no test is named for, nor of a mutant not killed by a test', function (): void {
    $mutants = Mutants::of(
        killedBy('src/Loop.php', 'unnamed', MutantStatus::Killed),
        killedBy('src/Loop.php', 'survived', MutantStatus::Survived),
        killedBy('src/Loop.php', 'analysed', MutantStatus::KilledByStaticAnalysis, 'LoopTest::a'),
        killedBy('src/Loop.php', 'timed out', MutantStatus::TimedOut, 'LoopTest::a'),
    );

    expect(askedKillControls(KillControls::of($mutants, killersMap(), killBounds())))->toBe([]);
});

it('lets a kill stand where its control passed, and leaves it unjudged, saying why, where it failed, ran out or never ran', function (ControlRun $found, MutantStatus $status, string $reason): void {
    $mutants = Mutants::of(killedBy('src/Loop.php', '1', MutantStatus::Killed, 'LoopTest::a'));
    $controls = KillControls::of($mutants, killersMap(), killBounds());
    $applied = [...$controls->applied($mutants, ControlRuns::none()->with([...$controls->asked()][0], $found), Controls::none())];
    $said = $applied[0]->reason();

    expect([$applied[0]->status(), $said instanceof Reason ? $said->text() : ''])->toBe([$status, $reason]);
})->with([
    'tests that pass unmutated' => [ControlRun::passed(Seconds::of(2.0)), MutantStatus::Killed, ''],
    'tests that fail unmutated' => [ControlRun::failed(), MutantStatus::Unjudged, KillControls::FAILS_UNMUTATED],
    'tests that run out unmutated' => [ControlRun::ranOut(), MutantStatus::Unjudged, KillControls::RAN_OUT],
    'a control never run' => [ControlRun::unrun('the file is gone'), MutantStatus::Unjudged, sprintf(KillControls::UNRUN, 'the file is gone')],
]);

it('says a control the runs hold nothing for never ran, and leaves a kill whose control was left unjudged by the budget', function (): void {
    $mutants = Mutants::of(
        killedBy('src/Loop.php', '1', MutantStatus::Killed, 'LoopTest::a'),
        killedBy('src/Tax.php', '2', MutantStatus::Killed, 'LoopTest::a'),
    );
    $controls = KillControls::of($mutants, killersMap(), killBounds());
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

        return static fn(): int => count(KillControls::of($mutants, killersMap(), killBounds())
            ->applied($mutants, ControlRuns::none(), Controls::none()));
    };

    expect($timing(10)())->toBe(10)
        ->and(Growth::of(1000, $timing))->toBeLessThan(Growth::LINEAR);
});
