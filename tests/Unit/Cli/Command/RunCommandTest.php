<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Command\PlanCommand;
use NightWorksIO\MutationGate\Cli\Command\RunCommand;
use NightWorksIO\MutationGate\Cli\Command\VerdictCommand;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\LastRun;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanFile;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Report\Problems;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Planned;
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
    $inCi ? new CiPlanFake(RunOn::at(Scope::branch('feature'), Scope::branch('main'))) : Flows::ci(),
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

it('runs the shard the environment names, into the results directory it is named', function () use ($floored): void {
    $project = FlowCommands::project();
    $composition = FlowCommands::over(
        $floored(50),
        $project,
        ScriptedRunner::fixture(),
        new ProofStoreFake(),
        Flows::ci(),
        Variables::of(['SHARD' => '1']),
    );
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

it('takes no --security beside --plan, since a shard makes the mutants its plan was made for', function () use ($composed): void {
    $project = FlowCommands::project();
    $composition = $composed($project, 50);
    FlowCommands::run(PlanCommand::command($composition));

    $ran = FlowCommands::run(RunCommand::command($composition), '--plan=.mutation-gate/plan.json --shard=1 --security');

    expect($ran->code)->toBe(2)
        ->and($ran->errors)
        ->toBe("A shard makes the mutants its plan was made for, so run --plan takes no --security. Give it to plan.\n");
});

it('plans, runs and judges the security mutators alone, holding only the security sets', function () use ($floored): void {
    $project = FlowCommands::project(
        '"extensions": ["NightWorksIO\\\\MutationGateSecurity\\\\SecurityExtension"], "mutators": {"sets": ["security"]}',
    );
    $composition = FlowCommands::over(
        $floored(100),
        $project,
        ScriptedRunner::fixture(),
        new ProofStoreFake(),
        Flows::ci(),
        Variables::of([]),
    );

    $planned = FlowCommands::run(PlanCommand::command($composition), '--security');
    $ran = FlowCommands::run(RunCommand::command($composition), '--security');

    expect($planned->code)->toBe(0)
        ->and((string) file_get_contents(sprintf('%s/.mutation-gate/plan.json', $project)))->toContain('"security": true')
        ->and($ran->code)->toBe(0)
        ->and($ran->output)->toContain('--security judges only the security sets');
});

it('cannot make mutants with the security mutators alone where the config turns none on', function (
    string $command,
    string $options,
) use ($composed): void {
    $project = FlowCommands::project();
    $composition = $composed($project, 50);
    LastRun::keep(Directory::at($project), Planned::twoShards()->briefed(Briefing::standard()->securityOnly()));
    $commands = [
        'run' => RunCommand::command($composition),
        'plan' => PlanCommand::command($composition),
        'verdict' => VerdictCommand::command($composition),
    ];

    $ran = FlowCommands::run($commands[$command], $options);

    expect($ran->code)->toBe(2)
        ->and($ran->errors)->toBe(<<<'SAID'
            --security makes mutants with the security-tagged mutators, and the config turns none on.
            Turn on the security set in mutators.sets, or a preset that offers it.

            SAID);
})->with([
    'run --security' => ['run', '--security'],
    'plan --security' => ['plan', '--security'],
    'a shard of a plan made with --security' => ['run', '--plan=.mutation-gate/plan.json --shard=1'],
    'the verdict of a plan made with --security' => ['verdict', '--plan=.mutation-gate/plan.json'],
]);

/** A project whose PHPUnit config declares the suites `unit` and `feature`. */
$suited = static function (): string {
    $project = FlowCommands::project();
    Scratch::write($project, 'phpunit.xml', <<<'XML'
        <?xml version="1.0"?>
        <phpunit>
            <testsuites>
                <testsuite name="unit"><directory>tests</directory></testsuite>
                <testsuite name="feature"><directory>features</directory></testsuite>
            </testsuites>
        </phpunit>
        XML);

    return $project;
};

it('takes no --suite beside --plan, since a shard runs the tests its plan was made for', function () use ($composed, $suited): void {
    $project = $suited();
    $composition = $composed($project, 50);
    FlowCommands::run(PlanCommand::command($composition));

    $ran = FlowCommands::run(RunCommand::command($composition), '--plan=.mutation-gate/plan.json --shard=1 --suite=unit');

    expect($ran->code)->toBe(2)
        ->and($ran->errors)
        ->toBe("A shard runs the tests its plan was made for, so run --plan takes no --suite. Give it to plan.\n");
});

it('plans, runs and judges one suite\'s tests alone, holding no floor', function () use ($composed, $suited): void {
    $project = $suited();
    $composition = $composed($project, 100);

    $planned = FlowCommands::run(PlanCommand::command($composition), '--suite=unit');
    $ran = FlowCommands::run(RunCommand::command($composition), '--suite=unit');
    $whole = FlowCommands::run(RunCommand::command($composed($suited(), 100)));

    expect($planned->code)->toBe(0)
        ->and((string) file_get_contents(sprintf('%s/.mutation-gate/plan.json', $project)))->toContain('"suite": "unit"')
        ->and($ran->code)->toBe(0)
        ->and($ran->output)->toContain('--suite judges no floor')
        ->and($whole->code)->toBe(1);
});

it('takes no --suite beside --coverage, whose map may hold every suite\'s tests', function (string $command) use (
    $composed,
    $suited,
): void {
    $composition = $composed($suited(), 50);
    $commands = ['run' => RunCommand::command($composition), 'plan' => PlanCommand::command($composition)];

    $ran = FlowCommands::run($commands[$command], '--suite=unit --coverage=.mutation-gate/coverage');

    expect($ran->code)->toBe(2)
        ->and($ran->errors)->toBe(<<<'SAID'
            --suite runs the coverage run with one suite's tests, so it takes no --coverage.
            The map --coverage names may hold every suite's tests.

            SAID);
})->with(['run', 'plan']);

it('cannot judge by a suite the PHPUnit config does not declare', function (string $command, string $options) use (
    $composed,
    $suited,
): void {
    $project = $suited();
    $composition = $composed($project, 50);
    LastRun::keep(Directory::at($project), Planned::twoShards()->briefed(Briefing::standard()->inSuite(SuiteName::of('e2e'))));
    $commands = [
        'run' => RunCommand::command($composition),
        'plan' => PlanCommand::command($composition),
        'verdict' => VerdictCommand::command($composition),
    ];

    $ran = FlowCommands::run($commands[$command], $options);

    expect($ran->code)->toBe(2)
        ->and($ran->errors)->toBe("--suite=e2e names no test suite. The PHPUnit config declares: unit, feature.\n");
})->with([
    'run --suite' => ['run', '--suite=e2e'],
    'plan --suite' => ['plan', '--suite=e2e'],
    'a shard of a plan made with --suite' => ['run', '--plan=.mutation-gate/plan.json --shard=1'],
    'the verdict of a plan made with --suite' => ['verdict', '--plan=.mutation-gate/plan.json'],
]);

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
        ->and($ran->errors)->toBe("Coverage: there is no kept map, so every test was measured.\n")
        ->and($ran->output)->toStartWith(sprintf(
            "No earlier run of this branch to re-check.\nWrote memory:refs/heads/feature.\nmutation-gate: %s\n",
            $judgement,
        ))
        ->and(is_file(sprintf('%s/.mutation-gate/results/1.json', $project)))->toBeTrue()
        ->and(is_file(sprintf('%s/.mutation-gate/plan.json', $project)))->toBeTrue()
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
        ->and($ran->output)->toStartWith(sprintf(
            "No earlier run of this branch to re-check.\nWrote memory:refs/heads/feature.\nmutation-gate: %s\n",
            $judgement,
        ));
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
            Wrote memory:refs/heads/main/coverage.json.gz.
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
            "Wrote memory:refs/heads/main.\nWrote memory:refs/heads/main/coverage.json.gz.\nNo floor in mutation-gate.baseline.json rose.\nmutation-gate: failed\n",
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
    'in CI' => ['', true, "No earlier run of this branch to re-check.\nWrote memory:refs/heads/feature."],
    'changed since a ref' => [
        '--changed-since=base',
        false,
        "Wrote memory:refs/heads/main.\nWrote memory:refs/heads/main/coverage.json.gz.",
    ],
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
            : sprintf("Coverage: there is no kept map, so every test was measured.\n%s/.mutation-gate/results/1.json could not be written.\n", $project));
})->with(['plan', 'results']);

it('cannot judge a run whose plan it could not leave for explain, and runs no shard of it', function () use ($composed): void {
    $project = FlowCommands::project();
    mkdir(sprintf('%s/.mutation-gate/plan.json', $project), recursive: true);

    $ran = FlowCommands::run(RunCommand::command($composed($project, 30)));

    expect([$ran->code, $ran->output, $ran->errors])
        ->toBe([2, '', sprintf("Coverage: there is no kept map, so every test was measured.\n%s/.mutation-gate/plan.json could not be written.\n", $project)])
        ->and(is_file(sprintf('%s/.mutation-gate/results/1.json', $project)))->toBeFalse();
});

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

it('names the holding tests that judged a held unit\'s survivor, in the run that mutated it and in one its proof answers for', function (): void {
    $composition = FlowCommands::composition(FlowCommands::project(), ScriptedRunner::fixture(), new ProofStoreFake(), Flows::ci());

    $ran = FlowCommands::run(RunCommand::command($composition), '--full');
    $proved = FlowCommands::run(RunCommand::command($composition), '--full');
    $judged = "\n      Judged by: tests/HeldTest.php::it doubles\n";

    expect($ran->output)->toContain($judged)
        ->and($proved->output)->toContain($judged)
        ->and($proved->output)->toContain('2: 0 run, 2 proved, 0 carried.');
});

it('writes the sticky comment in its planned state before it runs a pull request\'s plan, and not for one shard', function () use (
    $floored,
): void {
    $project = FlowCommands::project('"ci": {"plan": "json"}');
    $composition = FlowCommands::over(
        $floored(0),
        $project,
        ScriptedRunner::fixture(),
        new ProofStoreFake(),
        Flows::ci(),
        Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_EVENT_NAME' => 'pull_request', 'GITHUB_TOKEN' => 'secret']),
    );
    $notOne = 'This run is not for a pull request, so there is no comment to write.';
    [$ran, $shard] = Environment::during(['GITHUB_EVENT_NAME' => 'push'], static fn(): ArrayObject => new ArrayObject([
        FlowCommands::run(RunCommand::command($composition), '--shards=1'),
        FlowCommands::run(RunCommand::command($composition), '--plan=.mutation-gate/plan.json --shard=1'),
    ]));

    expect($ran instanceof FlowCommands ? $ran->output : $ran)->toStartWith(sprintf("%s\n", $notOne))
        ->and($shard instanceof FlowCommands ? sprintf("%s%s", $shard->output, $shard->errors) : $shard)->not->toContain($notOne);
});

it('re-checks the branch\'s last survivors before it runs the shards, and goes on where they cannot be', function (
    bool $runs,
    string $said,
) use ($floored): void {
    $project = FlowCommands::project();
    $store = new ProofStoreFake();
    $store->write(Scope::branch('feature'), Ledger::empty()->withProof(Proof::of(
        Digest::sha256Of('src/Held.php earlier'),
        Path::of('src/Held.php'),
        Flows::mutantsOf('src/Held.php'),
        Run::of('github:earlier', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
    )));
    $runner = $runs
        ? ScriptedRunner::fixture()
        : ScriptedRunner::fixture()->answeringInTurn(CannotJudge::because('The runner is busy.'), MutationResult::of(Mutants::none(), 0));
    $composition = FlowCommands::over(
        $floored(0),
        $project,
        $runner,
        $store,
        new CiPlanFake(RunOn::at(Scope::branch('feature'), Scope::branch('main'))),
        Variables::of(['CI' => 'true']),
    );

    $ran = FlowCommands::run(RunCommand::command($composition));

    expect(substr($ran->output, 0, strlen($said)))->toBe($said)
        ->and($ran->code)->not->toBe(2);
})->with([
    'found again' => [true, "Survivors re-checked: 1 of 1 still survives.\n  still survives: src/Held.php:11, Plus, 8705b7dc7d27\n"],
    'not run' => [false, "The last run's survivors could not be re-checked first: The runner is busy.\n"],
]);
