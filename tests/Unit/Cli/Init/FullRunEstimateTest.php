<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\Output;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Cli\Init\FullRunEstimate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;

afterEach(function (): void {
    Scratch::sweep();
});

/** The estimate of a full run in a project whose suite this runner runs. */
function fullRunEstimateIn(string $project, Runner $runner): FullRunEstimate
{
    return new FullRunEstimate(
        FlowCommands::composition($project, $runner, new ProofStoreFake(), Flows::ci()),
        new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER))),
        $project,
    );
}

/** The settings a config naming Pest comes to. */
function fullRunEstimateSettings(): Settings
{
    return Configs::settings(['runner' => 'pest']);
}

/**
 * init's command line, with these options.
 *
 * @param array<string, bool> $options
 */
function fullRunEstimateInput(array $options = []): InputInterface
{
    return new ArrayInput($options, FullRunEstimate::options(new Command('init'))->getDefinition());
}

it('measures a full run by one coverage run of the suite, cut as the config asks, and says what it rests on', function (): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());

    $said = fullRunEstimateIn(FlowCommands::project(), $runner)
        ->said(fullRunEstimateInput(), fullRunEstimateSettings(), Output::Written, fresh: true);

    expect($said)->toBe(<<<'SAID'
        One coverage run of the suite measured a full run. Shards the config cuts it into: 1.
        The plan expects about 1m of wall time and 1m of runner time: 0% learned, 0% measured, 100% guessed.
        SAID)
        ->and($runner->asked())->toHaveCount(1);
});

it('names each file most of the suite runs through that nothing holds, with the #[Holds] that cuts it', function (): void {
    $map = CoverageMap::empty();

    foreach (range(1, 20) as $test) {
        $id = TestId::of(sprintf('Test%d', $test));
        $map = $map->timed($id, Seconds::of(0.1))->covered(Path::of('src/Money.php'), Line::of(3), $id);
    }

    $said = fullRunEstimateIn(FlowCommands::project(), new CoverageAsked(ScriptedRunner::fixture(), $map))
        ->said(fullRunEstimateInput(), fullRunEstimateSettings(), Output::Written, fresh: true);

    expect($said)->toEndWith(<<<'SAID'
        `src/Money.php` is run by 20 of 20 tests and nothing holds it; each of its mutants runs most of the suite.
        Hold it with the tests that assert what it does: #[Holds('src/Money.php')] on them.
        SAID);
});

it('says why a full run could not be measured, so it is fixed before a first run', function (): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), CannotJudge::because('1 test failed.'));

    expect(fullRunEstimateIn(FlowCommands::project(), $runner)
        ->said(fullRunEstimateInput(), fullRunEstimateSettings(), Output::Written, fresh: true))
        ->toBe('A full run could not be estimated: 1 test failed.');
});

it('estimates from lines of code, running nothing, with --no-measure or where init prints what it made', function (
    bool $noMeasure,
    Output $output,
): void {
    $project = FlowCommands::project();
    Scratch::write($project, 'phpunit.xml', '<phpunit><source><include><directory>src</directory></include></source></phpunit>');
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());

    $said = fullRunEstimateIn($project, $runner)
        ->said(fullRunEstimateInput(['--no-measure' => $noMeasure]), fullRunEstimateSettings(), $output, fresh: true);

    expect($said)->toBe(<<<'SAID'
        A full run is estimated at about 0s in one job, from its lines of code. A coverage run of the suite,
        which init runs without --no-measure, measures it.
        SAID)
        ->and($runner->asked())->toBe([]);
})->with([
    '--no-measure' => [true, Output::Written],
    '--dry-run' => [false, Output::Printed],
]);

it('says nothing of a project whose config was here before', function (): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());

    expect(fullRunEstimateIn(FlowCommands::project(), $runner)
        ->said(fullRunEstimateInput(), fullRunEstimateSettings(), Output::Written, fresh: false))->toBe('')
        ->and($runner->asked())->toBe([]);
});
