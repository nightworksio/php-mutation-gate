<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\RunCommand;
use NightWorksIO\MutationGate\Cli\Command\StubCommand;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

/** The flows over a project, its last run done in full by this runner, the fixture's where none is given. */
$ran = static function (string $project, ScriptedRunner ...$runner): Composition {
    $composition = FlowCommands::composition($project, $runner === [] ? ScriptedRunner::fixture() : $runner[0], new ProofStoreFake(), Flows::ci());
    FlowCommands::run(RunCommand::command($composition), '--full');

    return $composition;
};

it('prints a failing Pest test for a survivor, under the test file of its class it goes in, and writes nothing', function () use ($ran): void {
    $project = FlowCommands::project();
    $stubbed = FlowCommands::run(StubCommand::command($ran($project)), 'id=49e02f');

    expect($stubbed->code)->toBe(0)
        ->and($stubbed->errors)->toBe('')
        ->and($stubbed->output)->toBe(<<<'PHP'
            // Add to tests/MoneyTest.php:

            it('kills mutant 49e02fb39669', function (): void {
                // src/Money.php:16  GreaterThan  survived  49e02fb39669
                //     @@ @@
                //     -return $amount > 100;
                //     +return $amount >= 100;
                // No test uses a value at the boundary of `$amount > 100`.
                // Call it at the boundary, then one step past it:
                // expect(/* the code it changes */)->toBe(/* at the boundary */);
                // expect(/* the code it changes */)->toBe(/* one step past it */);
                expect(true)->toBeFalse('Mutant 49e02fb39669, survived: No test uses a value at the boundary of `$amount > 100`. Fill this test in.');
                // Where no test can kill it, leave it out in ignores.entries instead, with a reason:
                // {"mutant":"49e02fb39669","reason":""}
            });

            PHP)
        ->and(file_get_contents(sprintf('%s/tests/MoneyTest.php', $project)))->toBe(Flows::FILES['tests/MoneyTest.php']);
});

it('adds the test to that file with --write, and says so', function () use ($ran): void {
    $project = FlowCommands::project();
    $stubbed = FlowCommands::run(StubCommand::command($ran($project)), 'id=95e61b --write');
    $written = (string) file_get_contents(sprintf('%s/tests/MoneyTest.php', $project));

    expect([$stubbed->code, $stubbed->output])->toBe([0, "Added a test for mutant 95e61bd8bf62 to tests/MoneyTest.php.\n"])
        ->and($written)->toStartWith(sprintf("%s\nit('kills mutant 95e61bd8bf62', function (): void {\n", Flows::FILES['tests/MoneyTest.php']))
        ->and($written)->toEndWith("\n});\n");
});

it('writes a new file in the runner\'s style where no test file is there, holding the unit with #[Holds], and adds to it after', function () use ($ran): void {
    $project = FlowCommands::project();
    $composition = $ran($project);
    $created = FlowCommands::run(StubCommand::command($composition), 'id=8705b7 --write');
    $first = (string) file_get_contents(sprintf('%s/tests/HeldTest.php', $project));
    $added = FlowCommands::run(StubCommand::command($composition), 'id=8705b7 --write');

    expect([$created->code, $created->output])->toBe([0, "Wrote tests/HeldTest.php, with a test for mutant 8705b7dc7d27.\n"])
        ->and($first)->toStartWith("<?php\n\ndeclare(strict_types=1);\n\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class HeldTest extends TestCase\n{\n    #[\\NightWorksIO\\MutationGate\\Attribute\\Holds('src/Held.php')]\n    public function testKillsMutant8705b7dc7d27(): void\n")
        ->and([$added->code, $added->output])->toBe([0, "Added a test for mutant 8705b7dc7d27 to tests/HeldTest.php.\n"])
        ->and(substr_count((string) file_get_contents(sprintf('%s/tests/HeldTest.php', $project)), 'public function testKillsMutant8705b7dc7d27'))->toBe(2);
});

it('writes one test for a cluster, calling the function its first survivor is in', function () use ($ran): void {
    $project = FlowCommands::project();
    Scratch::write($project, 'src/Money.php', Verdicts::MONEY);
    $comparison = static fn(string $mutator, string $added): Mutant => Verdicts::mutant('src/Money.php:7', $mutator, MutatorFamily::Boundary, Verdicts::diff('if ($amount < $limit) {', $added));
    $composition = $ran($project, ScriptedRunner::fixture()->answering(Mutants::of(
        $comparison('LessThan', 'if ($amount <= $limit) {'),
        $comparison('LessThanNegotiation', 'if ($amount > $limit) {'),
    ), 0));
    $ranAgain = FlowCommands::run(RunCommand::command($composition), '--full');
    $id = preg_match('/\b(c[0-9a-f]{11})\b/', $ranAgain->output, $found) === 1 ? $found[1] : '';

    $stubbed = FlowCommands::run(StubCommand::command($composition), sprintf('id=%s', $id));

    expect($stubbed->code)->toBe(0)
        ->and($stubbed->output)->toContain(sprintf("it('kills cluster %s', function (): void {\n", $id))
        ->and($stubbed->output)->toContain("\n    // \$money->fits(\$amount, \$limit);\n")
        ->and($stubbed->output)->toContain("\n    // expect(\$money->fits(\$amount, \$limit))->toBe(/* at the boundary */);\n");
});

it('exits 2, writing nothing, for a mutant there is nothing to stub for', function () use ($ran): void {
    $project = FlowCommands::project();
    $stubbed = FlowCommands::run(StubCommand::command($ran($project)), 'id=216c06 --write');

    expect([$stubbed->code, $stubbed->output, $stubbed->errors])
        ->toBe([2, '', "Mutant 216c068f2e8d is killed by timeout: nothing to stub. Its tests ran far past their usual time with it in place, so the timeout counts as a kill.\n"])
        ->and(file_get_contents(sprintf('%s/tests/MoneyTest.php', $project)))->toBe(Flows::FILES['tests/MoneyTest.php']);
});

it('exits 2 for a style the file it goes in is not written in, and for one it does not know', function () use ($ran): void {
    $composition = $ran(FlowCommands::project());
    $mixed = FlowCommands::run(StubCommand::command($composition), 'id=49e02f --style=phpunit');
    $unknown = FlowCommands::run(StubCommand::command($composition), 'id=49e02f --style=spec');
    $pest = FlowCommands::run(StubCommand::command($composition), 'id=49e02f --style=pest');

    expect([$mixed->code, $mixed->errors])
        ->toBe([2, "tests/MoneyTest.php holds pest tests, so a phpunit test cannot go in it. Leave out --style to write one in its style.\n"])
        ->and([$unknown->code, $unknown->errors])->toBe([2, "--style is spec; stub writes pest or phpunit.\n"])
        ->and($pest->code)->toBe(0);
});

it('writes a new file in the style asked for, where none is there', function () use ($ran): void {
    $stubbed = FlowCommands::run(StubCommand::command($ran(FlowCommands::project())), 'id=8705b7 --style=pest');

    expect($stubbed->output)->toStartWith("// A new file, tests/HeldTest.php:\n\n<?php\n\ndeclare(strict_types=1);\n\nit('kills mutant 8705b7dc7d27', function (): void {\n")
        ->and($stubbed->output)->toEndWith("\n})->group('holds:src/Held.php');\n");
});

it('exits 2 where the file it goes in cannot be written', function () use ($ran): void {
    $project = FlowCommands::project();
    $composition = $ran($project);
    mkdir(sprintf('%s/tests/HeldTest.php', $project));

    $stubbed = FlowCommands::run(StubCommand::command($composition), 'id=8705b7 --write');

    expect([$stubbed->code, $stubbed->errors])->toBe([2, sprintf("%s/tests/HeldTest.php could not be written.\n", $project)]);
});

it('exits 2 for what is no id, and for a mutant no record holds', function () use ($ran): void {
    $composition = $ran(FlowCommands::project());
    $misspelled = FlowCommands::run(StubCommand::command($composition), 'id=49E02F');
    $unrecorded = FlowCommands::run(StubCommand::command($composition), 'id=abcdef');

    expect([$misspelled->code, $misspelled->errors])->toBe([2, "\"49E02F\" is not a mutant id. Give the twelve lowercase hex characters every report prints, or the first six or more.\n"])
        ->and([$unrecorded->code, $unrecorded->errors])->toBe([2, "No ledger read holds a mutant abcdef names. Run mutation-gate on the code that has it to record it first.\n"]);
});
