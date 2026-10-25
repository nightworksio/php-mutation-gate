<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Dependents;
use NightWorksIO\MutationGate\Cli\Flow\NameGraph;
use NightWorksIO\MutationGate\Cli\Flow\PrintBaselines;
use NightWorksIO\MutationGate\Cli\Flow\WarmedUp;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Analysis\DependentCap;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\PreCheckable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\RecordingChecker;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/** A mutant its runner prints the file of before it mutates it. */
function printedBaseline(Mutant $mutant): PreCheckable
{
    return PreCheckable::of(
        $mutant,
        Checkable::printed(Contents::of('<?php // the print'), Contents::of(sprintf('<?php // %s', $mutant->id()->value()))),
        Seconds::of(10.0),
    );
}

it('keeps the baseline of each file a batch before it printed, and leaves to its tests a file whose print could not be written', function (): void {
    $project = Scratch::directory();
    $money = [...Flows::mutantsOf('src/Money.php')][0];
    $held = [...Flows::mutantsOf('src/Held.php')][0];
    $blocked = sprintf('%s/%s', $project, Workspace::checkedOriginal($held->id())->value());
    Scratch::write($project, 'elsewhere.php', '<?php');
    mkdir(dirname($blocked), recursive: true);
    symlink(sprintf('%s/elsewhere.php', $project), $blocked);
    $identity = AnalyserIdentity::of('fake', '1.0.0', Digest::sha256Of('{}'));
    $originals = Findings::of(Finding::error(Path::of('src/Money.php'), 'known', 'The originals already have this.'));
    $checker = new RecordingChecker(
        new StaticCheckerFake($identity, $originals, [Workspace::checkedOriginal($money->id())->value() => $originals]),
        $project,
    );
    $adapters = Flows::adapters($project, [], ScriptedRunner::fixture(), $checker);
    $warm = new WarmedUp($checker, $identity, $originals, Seconds::of(1.0), new Dependents(new NameGraph($adapters), DependentCap::standard()));

    $baselines = new PrintBaselines($adapters, $warm, Seconds::of(60.0))
        ->of([printedBaseline($money)], ProcessCount::single())
        ->of([printedBaseline($held)], ProcessCount::single());

    expect([$baselines->baseline(printedBaseline($money)), $baselines->baseline(printedBaseline($held))])
        ->toEqual([$originals, CannotJudge::because(sprintf('%s is a link, so the gate does not write through it.', $blocked))]);
});
