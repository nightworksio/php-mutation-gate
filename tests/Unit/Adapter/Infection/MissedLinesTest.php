<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\MissedLines;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads each statement line Clover counts no run of, and no method line or line some run reached', function (): void {
    $root = (string) realpath(Scratch::directory());
    $project = Project::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.gate'));
    Scratch::write($root, 'coverage/clover.xml', sprintf(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <coverage generated="1"><project timestamp="1">
          <file name="%1$s/src/Money.php">
            <class name="Money" namespace="App"><metrics complexity="1" methods="1" coveredmethods="0" statements="3" coveredstatements="1" elements="4" coveredelements="1"/></class>
            <line num="9" type="method" name="add" visibility="public" complexity="1" crap="2" count="0"/>
            <line num="11" type="stmt" count="2"/>
            <line num="12" type="stmt" count="0"/>
            <metrics loc="14" ncloc="14" classes="1" methods="1" coveredmethods="0" conditionals="0" coveredconditionals="0" statements="2" coveredstatements="1" elements="3" coveredelements="1"/>
          </file>
          <file name="%1$s/src/Tax.php">
            <line num="4" type="stmt" count="0"/>
          </file>
        </project></coverage>
        XML, $root));

    expect(MissedLines::in($project, DiskPath::of(sprintf('%s/coverage', $root))))->toEqual([
        CoveredLine::of(Path::of('src/Money.php'), 12),
        CoveredLine::of(Path::of('src/Tax.php'), 4),
    ])->and(MissedLines::in($project, DiskPath::of(sprintf('%s/elsewhere', $root))))->toEqual(CannotJudge::because(sprintf(
        '%s/elsewhere/clover.xml is not there or is not a Clover report, so the gate cannot say which lines no test ran.',
        $root,
    )));
});
