<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\PlanCommand;
use NightWorksIO\MutationGate\Cli\Command\RunCommand;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanFile;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Report\Problems;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

/** The one tree, `src`, held to this floor; the fixture scores 40 over it. */
$floored = static fn(float $floor): Trees => Trees::of(
    Tree::at(Path::of('src'), Floor::of($floor), Package::at(Path::root())),
);

/**
 * How the command line composes a flow in a project over the tree held to
 * this floor, in CI or outside it. A run in CI is on a feature branch, so
 * that no badge is published.
 */
$composed = static fn(string $project, float $floor, bool $inCi = false): Composition => FlowCommands::over(
    $floored($floor),
    $project,
    ScriptedRunner::fixture(),
    new ProofStoreFake(),
    $inCi ? new CiPlanFake(ShardId::of(1), RunOn::at(Scope::branch('feature'), Scope::branch('main'))) : Flows::ci(),
    Variables::of($inCi ? ['CI' => 'true'] : []),
);

it('runs the shard it is named, leaves its result, and exits 0 whatever its mutants did', function () use (
    $composed,
): void {
    $project = FlowCommands::project();
    $composition = $composed($project, 50);
    FlowCommands::run(PlanCommand::command($composition), '--shards=2');

    $ran = FlowCommands::run(RunCommand::command($composition), '--plan=.mutation-gate/plan.json --shard=2');
    $result = ShardResultFile::decode(
        (string) file_get_contents(sprintf('%s/.mutation-gate/results/2.json', $project)),
    );

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toBe(sprintf("Wrote %s/.mutation-gate/results/2.json.\n", $project))
        ->and($ran->errors)->toBe('')
        ->and($result instanceof ShardResult ? $result->shard()->number() : $result)->toBe(2)
        ->and(is_file(sprintf('%s/.mutation-gate/results/1.json', $project)))->toBeFalse();
});

it('runs the shard the CI names, into the results directory it is named', function () use ($composed): void {
    $project = FlowCommands::project();
    $composition = $composed($project, 50);
    FlowCommands::run(PlanCommand::command($composition), '--shards=2');

    $ran = FlowCommands::run(RunCommand::command($composition), '--plan=.mutation-gate/plan.json --results=out');

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toBe(sprintf("Wrote %s/out/1.json.\n", $project));
});

it('cannot run a shard of a plan that is not there, or not one it can follow', function (
    string $input,
    string $why,
) use ($composed): void {
    $project = FlowCommands::project();
    Scratch::write($project, 'elsewhere.json', PlanFile::encode(Plan::of(
        Revision::ref('0ddba110c0ffee0ddba110c0ffee0ddba110c0ff'),
        Digest::sha256Of('base'),
        Keys::none(),
        Shards::none(),
    )));

    $ran = FlowCommands::run(RunCommand::command($composed($project, 50)), $input);

    expect($ran->code)->toBe(2)
        ->and($ran->output)->toBe('')
        ->and($ran->errors)->toBe(sprintf("%s\n", $why));
})->with([
    'no plan' => [
        '--plan=missing.json',
        'There is no plan at missing.json. Run mutation-gate plan, and hand its plan to every job.',
    ],
    'a plan of another commit' => [
        '--plan=elsewhere.json',
        sprintf(
            'The plan was made on %s, and this checkout is %s. Run a shard on the commit its plan was made on.',
            '0ddba110c0ffee0ddba110c0ffee0ddba110c0ff',
            Flows::HEAD,
        ),
    ],
]);

it('cannot run a shard it is named wrongly', function () use ($composed): void {
    $project = FlowCommands::project();
    $composition = $composed($project, 50);
    FlowCommands::run(PlanCommand::command($composition));

    $ran = FlowCommands::run(RunCommand::command($composition), '--plan=.mutation-gate/plan.json --shard=one');

    expect($ran->code)->toBe(2)
        ->and($ran->errors)->toBe("--shard=one is not a shard number.\n");
});

it('cannot run with a config it cannot read', function (string $input): void {
    $project = FlowCommands::project('"shards": {"max": 0}');

    $ran = FlowCommands::run(RunCommand::command(FlowCommands::composition(
        $project,
        ScriptedRunner::fixture(),
        new ProofStoreFake(),
        Flows::ci(),
    )), $input);

    expect($ran->code)->toBe(2)
        ->and($ran->errors)->toContain('shards.max:');
})->with([
    'one shard' => ['--plan=.mutation-gate/plan.json'],
    'all in one' => [''],
]);

it('plans, runs and judges in one process without a plan, and exits as the verdict does', function (
    float $floor,
    int $code,
    string $judgement,
) use ($composed): void {
    $project = FlowCommands::project();

    $ran = FlowCommands::run(RunCommand::command($composed($project, $floor, inCi: true)));

    expect($ran->code)->toBe($code)
        ->and($ran->errors)->toBe('')
        ->and($ran->output)->toStartWith(sprintf("Wrote memory:refs/heads/feature.\nmutation-gate: %s\n", $judgement))
        ->and(is_file(sprintf('%s/.mutation-gate/results/1.json', $project)))->toBeTrue()
        ->and(is_file(sprintf('%s/mutation-gate.baseline.json', $project)))->toBeFalse();
})->with([
    'below its floor' => [50.0, 1, 'failed'],
    'at its floor' => [40.0, 0, 'passed'],
]);

it('judges a tree at the floor the config declares for it, over the one its source declares', function (
    string $declared,
    int $code,
    string $judgement,
) use ($composed): void {
    $project = FlowCommands::project(sprintf('"trees": [{"path": "src", "floor": %s}]', $declared));

    $ran = FlowCommands::run(RunCommand::command($composed($project, 50.0, inCi: true)));

    expect($ran->code)->toBe($code)
        ->and($ran->output)->toStartWith(sprintf("Wrote memory:refs/heads/feature.\nmutation-gate: %s\n", $judgement));
})->with([
    'a declared floor the tree reaches, over a source floor it does not' => ['40', 0, 'passed'],
    'a declared floor above what it reaches' => ['60', 1, 'failed'],
]);

it('writes the floors a local full run raised into the baseline, and says which lines to commit', function () use (
    $composed,
): void {
    $project = FlowCommands::project();

    $ran = FlowCommands::run(RunCommand::command($composed($project, 30)));

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toStartWith(<<<'SAID'
            Wrote memory:refs/heads/main.
            Raised the floors in mutation-gate.baseline.json. Commit it:
              src: 40, was none
            mutation-gate: passed

            SAID)
        ->and(file_get_contents(sprintf('%s/mutation-gate.baseline.json', $project)))
        ->toBe(BaselineFile::encode(Baseline::of(Entry::of(Path::of('src'), Floor::of(40)))));
});

it('says no floor rose on a local full run that raised none', function () use ($composed): void {
    $project = FlowCommands::project();

    $ran = FlowCommands::run(RunCommand::command($composed($project, 50)));

    expect($ran->code)->toBe(1)
        ->and($ran->output)->toStartWith(
            "Wrote memory:refs/heads/main.\nNo floor in mutation-gate.baseline.json rose.\nmutation-gate: failed\n",
        );
});

it('raises no floor on a run in CI or one scoped to a change', function (
    string $input,
    bool $inCi,
    string $wrote,
) use ($composed): void {
    $project = FlowCommands::project();

    $ran = FlowCommands::run(RunCommand::command($composed($project, 30, $inCi)), $input);

    expect($ran->output)->toStartWith(sprintf("%s\nmutation-gate: passed\n", $wrote))
        ->and(is_file(sprintf('%s/mutation-gate.baseline.json', $project)))->toBeFalse();
})->with([
    'in CI' => ['', true, 'Wrote memory:refs/heads/feature.'],
    'changed since a ref' => ['--changed-since=base', false, 'Wrote memory:refs/heads/main.'],
]);

it('cannot judge a run it could not plan, or whose shards could not leave their results', function (
    string $how,
) use ($composed): void {
    $project = FlowCommands::project();
    $input = $how === 'plan' ? '--shards=0' : '';

    if ($how === 'results') {
        mkdir(sprintf('%s/.mutation-gate/results/1.json', $project), recursive: true);
    }

    $ran = FlowCommands::run(RunCommand::command($composed($project, 30)), $input);

    expect($ran->code)->toBe(2)
        ->and($ran->output)->toBe('')
        ->and($ran->errors)->toBe($how === 'plan'
            ? "--shards=0 is not a number of shards.\n"
            : sprintf("%s/.mutation-gate/results/1.json could not be written.\n", $project));
})->with(['plan', 'results']);

it('offers a plan to run a shard of, the shard, and where the results go, besides plan\'s options', function () use (
    $composed,
): void {
    $definition = RunCommand::command($composed(FlowCommands::project(), 50))->getDefinition();

    expect($definition->getOption('plan')->isValueRequired())->toBeTrue()
        ->and($definition->getOption('shard')->isValueRequired())->toBeTrue()
        ->and($definition->getOption('results')->isValueRequired())->toBeTrue()
        ->and($definition->hasOption('changed-since'))->toBeTrue();
});

it('prints the verdict as problems for an editor, framed for a background matcher, and exits as the verdict does', function (string $only, int $problems) use ($composed): void {
    $project = FlowCommands::project();

    $ran = FlowCommands::run(RunCommand::command($composed($project, 50)), sprintf('--output=problems%s', $only));
    $lines = explode("\n", rtrim($ran->output, "\n"));
    $matched = array_filter($lines, static fn(string $line): bool => preg_match(sprintf('/%s/', Problems::PATTERN), $line) === 1);

    expect($ran->code)->toBe(1)
        ->and($lines[0])->toBe(Problems::JUDGING)
        ->and($lines[count($lines) - 1])->toBe(Problems::JUDGED)
        ->and($matched)->toHaveCount($problems)
        ->and($ran->output)->not->toContain('mutation-gate: failed');
})->with([
    'every result' => ['', 3],
    'those on changed lines, of which a full run has none' => [' --only=changed', 0],
]);

it('refuses to run with an output it cannot print, and runs nothing', function () use ($composed): void {
    $project = FlowCommands::project();

    $ran = FlowCommands::run(RunCommand::command($composed($project, 50)), '--output=json');

    expect($ran->code)->toBe(2)
        ->and($ran->errors)->toBe("--output is console or problems, not \"json\".\n")
        ->and(is_dir(sprintf('%s/.mutation-gate/results', $project)))->toBeFalse();
});

it('names the tests that judged each survivor by their names, and marks each result a proof gave with the run it names', function (): void {
    $survivor = Verdicts::mutant('src/Money.php:11', 'Plus', MutatorFamily::Arithmetic, Verdicts::diff('return $amount + $tax;', 'return $amount - $tax;'));
    $composition = FlowCommands::composition(
        FlowCommands::project(),
        ScriptedRunner::fixture()->answering(Mutants::of($survivor), 0),
        new ProofStoreFake(),
        Flows::ci(),
    );

    $ran = FlowCommands::run(RunCommand::command($composition), '--full');
    $proved = FlowCommands::run(RunCommand::command($composition), '--output=problems');

    expect($ran->output)->toContain(sprintf(
        "\n      Judged by: tests/MoneyTest.php::it adds\n      %s It is judged by `MoneyTest::adds`.\n",
        'No test checks the result of `$amount + $tax` with a non-zero operand.',
    ))
        ->and($proved->output)->toContain(' (proved in run local:2026-09-30T12:00:00Z) [survived] ');
});
