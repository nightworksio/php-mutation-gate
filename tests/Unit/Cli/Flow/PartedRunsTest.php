<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\PartedRuns;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Runner\ExactRun;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\ProcessLog;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;

/** The fixture's first mutant of src/Money.php, timed out at the most of six seconds. */
function partedRunsHeavy(): Mutant
{
    $mutant = [...Flows::mutantsOf('src/Money.php')][0];

    return Mutant::of(
        $mutant->id(),
        $mutant->nativeId(),
        $mutant->location(),
        $mutant->mutation(),
        MutantStatus::TimedOut,
        Seconds::of(6.0),
    )->withLimit(Seconds::of(6.0));
}

/** A map in which two tests of 1.5 seconds each cover the heavy mutant's lines. */
function partedRunsMap(): CoverageMap
{
    $location = partedRunsHeavy()->location();

    return CoverageMap::of()
        ->covered($location->file(), $location->start(), TestId::of('a'), TestId::of('b'))
        ->timed(TestId::of('a'), Seconds::of(1.5))
        ->timed(TestId::of('b'), Seconds::of(1.5));
}

it('names in the log what each round runs, and says nothing of a mutant once judged', function (): void {
    $adapters = Flows::adapters(Flows::project(), [], RunnerFake::ofTheFixture());
    $log = new class implements ProcessLog {
        /** @var list<string> */
        public private(set) array $lines = [];

        public function say(string $line): void
        {
            $this->lines[] = $line;
        }
    };
    $parted = new PartedRuns($adapters, Flows::settings(), partedRunsMap(), HeldCovered::none(), $log);
    $heavy = $parted->among(
        Mutants::of(partedRunsHeavy()),
        new ExactRun(),
        LimitBounds::between(Seconds::of(1.0), Seconds::of(6.0))->startingIn(Seconds::of(0.1)),
    );
    $rounds = 0;

    $parted->judged(Mutants::of(partedRunsHeavy()), $heavy, static function (array $asked) use (&$rounds): array {
        $rounds++;
        $runs = ControlRuns::none();

        foreach ($asked as $control) {
            $runs = $runs->with($control, $rounds === 1 ? ControlRun::passed(Seconds::of(1.5)) : ControlRun::failed(TestIds::of(TestId::of('b'))));
        }

        return [$runs, Controls::none()];
    });
    $id = partedRunsHeavy()->id()->key();
    $location = partedRunsHeavy()->location();

    expect($log->lines)->toHaveCount($rounds)
        ->and($log->lines[0])->toContain(sprintf('mutation-gate: heavy mutant %s, %s:%d: part 1 of', $id, $location->file()->value(), $location->start()->number()))
        ->and($log->lines[1])->toContain('part 2 of');
});

it('serves each heavy mutant as its runner gives it to a static analyser, and judges it round after round', function (): void {
    $adapters = Flows::adapters(Flows::project(), [], RunnerFake::ofTheFixture());
    $parted = new PartedRuns($adapters, Flows::settings(), partedRunsMap(), HeldCovered::none());
    $heavy = $parted->among(
        Mutants::of(partedRunsHeavy()),
        new ExactRun(),
        LimitBounds::between(Seconds::of(1.0), Seconds::of(6.0))->startingIn(Seconds::of(0.1)),
    );
    $checkable = $adapters->runner->checkable(partedRunsHeavy());
    $rounds = [];

    $judged = $parted->judged(Mutants::of(partedRunsHeavy()), $heavy, static function (array $asked) use (&$rounds): array {
        $rounds[] = array_map(static fn(Control $control): ?Contents => $control->served() instanceof Contents ? $control->served() : null, $asked);
        $runs = ControlRuns::none();

        foreach ($asked as $control) {
            $runs = $runs->with($control, count($rounds) === 1 ? ControlRun::passed(Seconds::of(1.5)) : ControlRun::failed(TestIds::of(TestId::of('b'))));
        }

        return [$runs, Controls::none()];
    });

    expect($rounds)->toEqual([[$checkable instanceof CannotJudge ? null : $checkable->mutant()], [$checkable instanceof CannotJudge ? null : $checkable->mutant()]])
        ->and($judged instanceof Mutants ? array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), [...$judged]) : $judged)
        ->toBe([MutantStatus::Killed])
        ->and($parted->judged(Mutants::of(partedRunsHeavy()), $heavy, static fn(): array => [CannotJudge::because('no runner'), Controls::none()]))
        ->toEqual(CannotJudge::because('no runner'));
});
