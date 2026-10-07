<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlJudge;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\MutantControls;
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
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/** A killed mutant of `src/Loop.php`, by its native id. */
function controlledKill(string $nativeId): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of('src/Loop.php'), 'LessThan', sprintf("-<\n+%s", $nativeId), 0),
        $nativeId,
        Location::of(Path::of('src/Loop.php'), Line::of(3), Line::of(3)),
        Mutation::of('LessThan', MutatorFamily::Boundary, ''),
        MutantStatus::Killed,
        Unmeasured::duration(),
    );
}

/** A control of `src/Loop.php` by one test, allowed this long. */
function loopControl(float $limit): Control
{
    return Control::of(Path::of('src/Loop.php'), TestIds::of(TestId::of('LoopTest::a')), Seconds::of($limit));
}

it('asks for each control once, however many mutants share it', function (): void {
    $controls = MutantControls::of([
        controlledKill('1')->id()->key() => loopControl(5.0),
        controlledKill('2')->id()->key() => loopControl(5.0),
        controlledKill('3')->id()->key() => loopControl(6.0),
    ]);

    expect(array_map(static fn(Control $control): float => $control->limit()->seconds(), [...$controls->asked()]))->toBe([5.0, 6.0]);
});

it('judges each mutant with a control by what it found, leaves one whose control was left unjudged, and the rest as they are', function (): void {
    $mutants = Mutants::of(controlledKill('ran'), controlledKill('left'), controlledKill('none'));
    $controls = MutantControls::of([
        controlledKill('ran')->id()->key() => loopControl(5.0),
        controlledKill('left')->id()->key() => loopControl(6.0),
    ]);
    $applied = [...$controls->applied(
        $mutants,
        ControlRuns::none()->with(loopControl(5.0), ControlRun::failed()),
        Controls::of(loopControl(6.0)),
        new class implements ControlJudge {
            public function judged(Mutant $mutant, ControlRun $run, Control $control): Mutant
            {
                return $mutant->because(Reason::that(sprintf('%s %s', $run->end()->value, $control->limit()->text())));
            }
        },
    )];

    expect($applied[0]->reason())->toEqual(Reason::that(sprintf('failed %s', Seconds::of(5.0)->text())))
        ->and($applied[1]->reason())->toEqual(OutOfTime::BeforeControlling->reason())
        ->and($applied[2])->toEqual(controlledKill('none'));
});
