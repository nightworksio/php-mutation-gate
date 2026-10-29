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
use NightWorksIO\MutationGate\Core\Analysis\DifferingPrint;
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

it('stands a print unlike the warm-up where the file as written, read from the print\'s place, analyses alike, and otherwise says which findings differ', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'src/Money.php', '<?php // Money as written');
    Scratch::write($project, 'src/Held.php', '<?php // Held as written');
    $money = [...Flows::mutantsOf('src/Money.php')][0];
    $held = [...Flows::mutantsOf('src/Held.php')][0];
    $identity = AnalyserIdentity::of('fake', '1.0.0', Digest::sha256Of('{}'));
    $placed = Findings::of(Finding::error(Path::of('src/Money.php'), 'disallowed.function', 'Calling config() is forbidden.'));
    $printed = Findings::of(Finding::error(Path::of('src/Held.php'), 'method.notFound', 'Call to an undefined method.'));
    $checker = new StaticCheckerFake($identity, Findings::none(), [
        Workspace::checkedOriginal($money->id())->value() => $placed,
        Workspace::checkedAsWritten($money->id())->value() => $placed,
        Workspace::checkedOriginal($held->id())->value() => $printed,
        Workspace::checkedAsWritten($held->id())->value() => Findings::none(),
    ]);
    $adapters = Flows::adapters($project, [], ScriptedRunner::fixture(), $checker);
    $warm = new WarmedUp($checker, $identity, Findings::none(), Seconds::of(1.0), new Dependents(new NameGraph($adapters), DependentCap::standard()));

    $baselines = new PrintBaselines($adapters, $warm, Seconds::of(60.0))
        ->of([printedBaseline($money), printedBaseline($held)], ProcessCount::single());
    $differing = $baselines->baseline(printedBaseline($held));

    expect($baselines->baseline(printedBaseline($money)))->toBe($placed)
        ->and($differing instanceof DifferingPrint ? $differing->detail() : $differing)
        ->toBe('The print alone reports `method.notFound` Call to an undefined method.; the file as written there alone reports no finding')
        ->and(is_dir(sprintf('%s/%s', $project, dirname(Workspace::checkedAsWritten($money->id())->value()))) ? scandir(sprintf('%s/%s', $project, dirname(Workspace::checkedAsWritten($money->id())->value()))) : [])
        ->toBe(['.', '..']);
});

it('weighs a print unlike the warm-up against the warm-up where the file as written cannot be read', function (): void {
    $project = Scratch::directory();
    $money = [...Flows::mutantsOf('src/Money.php')][0];
    $identity = AnalyserIdentity::of('fake', '1.0.0', Digest::sha256Of('{}'));
    $known = Findings::of(Finding::error(Path::of('src/Money.php'), 'known', 'Known.'));
    $checker = new StaticCheckerFake($identity, $known, [Workspace::checkedOriginal($money->id())->value() => Findings::none()]);
    $adapters = Flows::adapters($project, [], ScriptedRunner::fixture(), $checker);
    $warm = new WarmedUp($checker, $identity, $known, Seconds::of(1.0), new Dependents(new NameGraph($adapters), DependentCap::standard()));

    $baseline = new PrintBaselines($adapters, $warm, Seconds::of(60.0))
        ->of([printedBaseline($money)], ProcessCount::single())
        ->baseline(printedBaseline($money));

    expect($baseline instanceof DifferingPrint ? $baseline->detail() : $baseline)
        ->toBe('The print alone reports no finding; the warm-up alone reports `known` Known.');
});
