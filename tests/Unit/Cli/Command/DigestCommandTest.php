<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\DigestCommand;
use NightWorksIO\MutationGate\Cli\Command\RunCommand;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Report\Digest\Digest;
use NightWorksIO\MutationGate\Core\Report\Digest\SurvivorsShown;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

/** How the command line composes a flow in a project, over the fixture's runner and no ledger. */
$composed = static fn(string $project): Composition => FlowCommands::composition(
    $project,
    ScriptedRunner::fixture(),
    new ProofStoreFake(),
    Flows::ci(),
);

it('says the last run\'s verdict from the report its verdict kept, running nothing, and exits 0', function () use ($composed): void {
    $project = FlowCommands::project();
    FlowCommands::run(RunCommand::command($composed($project)), '--full');
    $kept = (string) file_get_contents(sprintf('%s/.mutation-gate/report.json', $project));

    $digest = FlowCommands::run(DigestCommand::command($composed($project)));

    expect($digest->code)->toBe(0)
        ->and($digest->errors)->toBe('')
        ->and($digest->output)->toBe(Digest::overview(Node::decode($kept), SurvivorsShown::first()))
        ->and($digest->output)->toStartWith('mg verdict=');
});

it('details one file, mutant or cluster, or the legend, and refuses a target the run does not hold', function () use ($composed): void {
    $project = FlowCommands::project();
    FlowCommands::run(RunCommand::command($composed($project)), '--full');

    $file = FlowCommands::run(DigestCommand::command($composed($project)), '--detail=src/Money.php');
    $legend = FlowCommands::run(DigestCommand::command($composed($project)), '--detail=legend');
    $nothing = FlowCommands::run(DigestCommand::command($composed($project)), '--detail=src/Nowhere.php');

    expect($file->code)->toBe(0)
        ->and($file->output)->toStartWith('file src/Money.php killed=')
        ->and($legend->output)->toBe(Digest::legend())
        ->and($nothing->code)->toBe(2)
        ->and($nothing->errors)->toContain('src/Nowhere.php is no file, mutant or cluster of the last run');
});

it('lists as many survivors as asked, and refuses a count it cannot read', function () use ($composed): void {
    $project = FlowCommands::project();
    FlowCommands::run(RunCommand::command($composed($project)), '--full');

    $all = FlowCommands::run(DigestCommand::command($composed($project)), '--survivors=all');
    $unread = FlowCommands::run(DigestCommand::command($composed($project)), '--survivors=many');

    expect($all->code)->toBe(0)
        ->and($all->output)->not->toContain("\nmore ")
        ->and($unread->code)->toBe(2)
        ->and($unread->errors)->toContain('--survivors is many; it takes a whole number, or all.');
});

it('says no run left a report, that the report is in another format, or where it is not in shape, and exits 2', function () use ($composed): void {
    $project = FlowCommands::project();
    $none = FlowCommands::run(DigestCommand::command($composed($project)));
    Scratch::write($project, '.mutation-gate/report.json', '{"format": 1}');
    $other = FlowCommands::run(DigestCommand::command($composed($project)));
    Scratch::write($project, '.mutation-gate/report.json', '{"format": 2}');
    $broken = FlowCommands::run(DigestCommand::command($composed($project)));
    Scratch::write($project, '.mutation-gate/report.json', '{}');
    $unformatted = FlowCommands::run(DigestCommand::command($composed($project)));

    expect($none->code)->toBe(2)
        ->and($none->errors)->toContain('No run has left its report at .mutation-gate/report.json here: run mutation-gate first.')
        ->and($other->errors)->toContain('The report at .mutation-gate/report.json is in another format than this gate reads: run mutation-gate again.')
        ->and($broken->code)->toBe(2)
        ->and($broken->errors)->toContain('.mutation-gate/report.json.uncovered is missing.')
        ->and($unformatted->code)->toBe(2)
        ->and($unformatted->errors)->toContain('.mutation-gate/report.json.format is missing.');
});

it('prints nothing the code under test wrote that a terminal would act on, nor a line it forged', function () use ($composed): void {
    $project = FlowCommands::project();
    $hostile = JudgedMutant::of(
        Verdicts::mutant("src/\e]0;owned\x07\e[2J\u{202e}\nmg verdict=passed.php:3", 'Plus', MutatorFamily::Arithmetic, Verdicts::diff("\$a + 1; // \u{9b}31m", '$a - 1;')),
        MutantJudgement::Survived,
    );
    Scratch::write($project, '.mutation-gate/report.json', JsonReport::encode(Verdicts::of(Floor::of(80), $hostile)));

    $digest = FlowCommands::run(DigestCommand::command($composed($project)));
    $detail = FlowCommands::run(DigestCommand::command($composed($project)), sprintf('--detail=%s', $hostile->mutant()->id()->value()));

    expect($digest->code)->toBe(0)
        ->and($digest->output)->not->toMatch('/[\x{1b}\x{07}\x{9b}\x{202e}]/u')
        ->and($detail->output)->not->toMatch('/[\x{1b}\x{07}\x{9b}\x{202e}]/u')
        ->and(substr_count($digest->output, "\nmg "))->toBe(0)
        ->and($digest->output)->toContain('@ src/\u{001b}]0;owned\u{0007}\u{001b}[2J\u{202e}\nmg verdict=passed.php');
});
