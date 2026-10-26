<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\RunnerMarkers;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

it('looks for the runner\'s markers in the files of even one unit, and asks nothing of none', function (): void {
    $refusing = static fn(Markers|CannotJudge $markers, Units $units): Units|CannotJudge => new RunnerMarkers(
        Flows::adapters(Scratch::directory(), [], ScriptedRunner::fixture()->marking($markers)),
        Flows::settings(),
    )->refusing($units);
    $marked = Markers::of(Marker::of('infection.json5 mutators.global-ignore', 'ignore', '{"path": "src/Money.php"}'));

    expect([
        $refusing($marked, Units::of(Unit::file(Path::of('src/Money.php')))),
        $refusing(CannotJudge::because('infection.json5 cannot be read.'), Units::none()),
    ])->toEqual([
        CannotJudge::because(<<<'SAID'
            The runner's own ignore markers hide mutants with no reason and no end,
            so the run cannot go ahead:
              infection.json5 mutators.global-ignore, ignore
                replaced by {"path": "src/Money.php"}
            Replace each with its entry in ignores.entries,
            or set ignores.native: allow while the project moves them there.
            SAID),
        Units::none(),
    ]);
});
