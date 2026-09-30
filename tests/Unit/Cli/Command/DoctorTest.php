<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\Doctor;
use NightWorksIO\MutationGate\Cli\Doctor\Measure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Described;
use NightWorksIO\MutationGate\Tests\Support\Doctored;
use NightWorksIO\MutationGate\Tests\Support\FakePhp;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Printed;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

$here = (string) getcwd();

afterEach(function () use ($here): void {
    chdir($here);
    Scratch::sweep();
});

/**
 * `doctor` over a copy of a fixture project, with these files besides, and a PHP that loads pcov.
 *
 * @param  array<string, string>     $input
 * @param  array<string, string>     $files each file's contents, by its path in the project
 * @return array{int, string, string} the exit code, and what it printed on each stream
 */
function doctored(string $fixture, array $input = [], array $files = []): array
{
    $project = Scratch::copy($fixture);
    Scratch::write($project, '.gitignore', ".mutation-gate/\n");

    foreach ($files as $path => $contents) {
        Scratch::write($project, $path, $contents);
    }

    $php = FakePhp::printing(Described::output(['pcov' => '1.0.12'], ['extension_dir' => '/nowhere']));
    $measure = new Measure(FlowCommands::composition($project, ScriptedRunner::fixture(), new ProofStoreFake(), Flows::ci()));
    $tester = new CommandTester(Doctor::command(Doctored::observed($project, sprintf('%s/php', $php)), $measure, Guide::unreleased()));
    $code = $tester->execute($input, ['capture_stderr_separately' => true]);
    $output = $tester->getOutput();

    return [$code, Printed::by($output), Printed::by($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)];
}

/**
 * What `doctor --format=json` writes in a project, with a PHP that loads pcov.
 *
 * @param array<string, bool> $input
 */
function doctorJson(string $project, string $php, Measure $measure, array $input): string
{
    $tester = new CommandTester(
        Doctor::command(Doctored::observed($project, sprintf('%s/php', $php)), $measure, Guide::unreleased()),
    );
    $tester->execute(['--format' => 'json', ...$input]);

    return $tester->getDisplay();
}

it('exits 1 where a finding would fail a run, and writes each as text', function (): void {
    [$code, $output, $errors] = doctored('tests/Fixtures/Projects/TwoRunners');

    expect([$code, $errors])->toBe([1, ''])
        ->and($output)->toStartWith("will fail  two-runners\n")
        ->and($output)->toEndWith("1 would fail a run, 0 would slow it, and 0 are advice.\n");
});

it('exits 0 where nothing would fail a run, and writes the public JSON when asked', function (): void {
    [$code, $output] = doctored('tests/Fixtures/Projects/Library', ['--format' => 'json']);
    $written = json_decode($output, associative: true);

    expect($code)->toBe(0)
        ->and($written)->toMatchArray(['format' => 1, 'failsARun' => false])
        ->and(Decoded::column($output, 'slug', 'findings'))->toBe(['tree-without-floor']);
});

it('finds no tree without a floor where the config declares the tree\'s floor', function (): void {
    [$code, $output] = doctored(
        'tests/Fixtures/Projects/Library',
        ['--format' => 'json'],
        ['mutation-gate.json' => '{"trees": [{"path": "src", "floor": 60}]}'],
    );

    expect($code)->toBe(0)
        ->and(Decoded::column($output, 'slug', 'findings'))->not->toContain('tree-without-floor');
});

it('cannot judge a format it does not write', function (): void {
    expect(doctored('tests/Fixtures/Projects/Library', ['--format' => 'xml']))
        ->toBe([2, '', "--format is xml; doctor writes text or json.\n"]);
});

it('runs the suite under --measure, and fails where measuring it fails', function (): void {
    $project = FlowCommands::project();
    Scratch::write($project, '.gitignore', ".mutation-gate/\n");
    $php = FakePhp::printing(Described::output(['pcov' => '1.0.12'], ['extension_dir' => '/nowhere']));
    $runner = new CoverageAsked(ScriptedRunner::fixture(), CannotJudge::because('1 test failed.'));
    $measure = new Measure(FlowCommands::composition($project, $runner, new ProofStoreFake(), Flows::ci()));
    $offline = Decoded::column(doctorJson($project, $php, $measure, []), 'slug', 'findings');

    expect($runner->asked())->toBe([])
        ->and(Decoded::column(doctorJson($project, $php, $measure, ['--measure' => true]), 'slug', 'findings'))
        ->toBe([...$offline, 'coverage-run-failed'])
        ->and($runner->asked())->toHaveCount(1);
});
