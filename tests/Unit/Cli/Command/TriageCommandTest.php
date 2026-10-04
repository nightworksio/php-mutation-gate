<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\TriageCommand;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\Varying;

afterEach(function (): void {
    Scratch::sweep();
});

/** `triage` run with these options in a project of this config, through this runner. */
$triage = static fn(ScriptedRunner $runner, string $options, string $config = ''): FlowCommands => FlowCommands::run(
    TriageCommand::command(FlowCommands::composition(FlowCommands::project($config), $runner, new ProofStoreFake(), Flows::ci())),
    $options,
);

/** Whether each request the runner was handed asked for each mutant's likely killers first. */
$killersFirst = static fn(ScriptedRunner $runner): array => array_map(
    static fn(MutationRequest $request): bool => $request->search()->ordering()->putsKillersFirst(),
    $runner->requests(),
);

it('lists every mutant that varied and exits 1, saying each run on standard error as it ends', function () use ($triage): void {
    $made = static fn(MutantStatus $status): MutationResult => MutationResult::of(Mutants::of(Varying::mutant(7, $status)), 0);
    $runner = ScriptedRunner::fixture()->answeringInTurn($made(MutantStatus::Killed), $made(MutantStatus::Survived));
    $id = Varying::mutant(7, MutantStatus::Killed)->id()->value();

    $triaged = $triage($runner, 'path=src/Money.php --repeat=2');

    expect($triaged->code)->toBe(1)
        ->and($triaged->errors)->toBe("Run 1 of 2 made 1 mutant.\nRun 2 of 2 made 1 mutant.\n")
        ->and($triaged->output)->toBe(implode("\n", [
            sprintf('src/Money.php:7  IncrementInteger  %s', $id),
            '    killed in run 1',
            '    survived in run 2',
            sprintf('    Reproduce: vendor/bin/mutation-gate reproduce %s', $id),
            '',
            '1 of 1 mutant varied over 2 runs.',
            '',
        ]));
});

it('runs a unit 5 times unless asked otherwise, and exits 0 where no mutant varied', function () use ($triage): void {
    $runner = ScriptedRunner::fixture();

    $triaged = $triage($runner, 'path=src/Money.php');

    expect([$triaged->code, $triaged->output])->toBe([0, "The 5 runs made 4 mutants, and none varied.\n"])
        ->and($triaged->errors)->toBe(implode('', array_map(
            static fn(int $run): string => sprintf("Run %d of 5 made 4 mutants.\n", $run),
            range(1, 5),
        )))
        ->and($runner->requests())->toHaveCount(5);
});

it('refuses a number of runs that is not a whole number of 2 or more, and runs nothing', function (string $repeat) use (
    $triage,
): void {
    $runner = ScriptedRunner::fixture();

    $triaged = $triage($runner, sprintf('path=src/Money.php --repeat=%s', $repeat));

    expect([$triaged->code, $triaged->output, $triaged->errors])
        ->toBe([2, '', sprintf("--repeat takes a whole number of 2 or more, not \"%s\".\n", $repeat)])
        ->and($runner->requests())->toBe([]);
})->with(['1', '0', '-3', '2.5', 'many']);

it('runs each mutant\'s tests in the order --order asks', function (string $order, bool $first) use (
    $triage,
    $killersFirst,
): void {
    $runner = ScriptedRunner::fixture();

    $triage($runner, sprintf('path=src/Money.php --repeat=2 --order=%s', $order), '"tests": {"order": "runner"}');

    expect($killersFirst($runner))->toBe([$first, $first]);
})->with([['killers-first', true], ['runner', false]]);

it('runs each mutant\'s tests in the order tests.order sets where --order is not given', function () use (
    $triage,
    $killersFirst,
): void {
    $runner = ScriptedRunner::fixture();

    $triage($runner, 'path=src/Money.php --repeat=2', '"tests": {"order": "runner"}');

    expect($killersFirst($runner))->toBe([false, false]);
});

it('refuses an order it does not know, and runs nothing', function () use ($triage): void {
    $runner = ScriptedRunner::fixture();

    $triaged = $triage($runner, 'path=src/Money.php --order=random');

    expect([$triaged->code, $triaged->errors])->toBe([2, "--order takes runner or killers-first, not \"random\".\n"])
        ->and($runner->requests())->toBe([]);
});

it('exits 2 where the config cannot be used, saying why', function () use ($triage): void {
    $triaged = $triage(ScriptedRunner::fixture(), 'path=src/Money.php', '"tests": {"order": "sideways"}');

    expect($triaged->code)->toBe(2)
        ->and($triaged->errors)->toContain('tests.order');
});

it('exits 2 where the path is not a unit, saying why', function () use ($triage): void {
    $triaged = $triage(ScriptedRunner::fixture(), 'path=src/Missing.php');

    expect([$triaged->code, $triaged->output, $triaged->errors])->toBe([
        2,
        '',
        "src/Missing.php is not a unit the gate mutates: give a file of a tree, or a held path.\n",
    ]);
});
