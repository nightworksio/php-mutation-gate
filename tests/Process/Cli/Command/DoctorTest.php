<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\Doctor;
use NightWorksIO\MutationGate\Cli\Doctor\Measure;
use NightWorksIO\MutationGate\Cli\Doctor\Online;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Described;
use NightWorksIO\MutationGate\Tests\Support\Doctored;
use NightWorksIO\MutationGate\Tests\Support\DoctorRuns;
use NightWorksIO\MutationGate\Tests\Support\FakePhp;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\GitHubAnswering;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

$holds = [
    'holds:src/Adapter/GitHub/RepositorySettings.php',
    'holds:src/Adapter/Infection/Setup.php',
    'holds:src/Cli/Command/Doctor.php',
    'holds:src/Cli/Config/Detected.php',
    'holds:src/Cli/Config/Effective.php',
    'holds:src/Cli/Doctor/Files.php',
    'holds:src/Cli/Doctor/Measure.php',
    'holds:src/Cli/Doctor/Observed.php',
    'holds:src/Cli/Flow/ProjectMemoryLimit.php',
    'holds:src/Core/Doctor/Check/CoverageRun.php',
    'holds:src/Core/Doctor/Check/OnlineRead.php',
    'holds:src/Core/Doctor/Check/TreeFloors.php',
    'holds:src/Core/Doctor/Check/TreesFound.php',
    'holds:src/Core/Doctor/DoctorJson.php',
    'holds:src/Core/Doctor/Observations.php',
    'holds:src/Core/Doctor/ProjectFiles.php',
    'holds:src/Core/Runner/MemoryCap.php',
];

$makeHere = static fn(): string => (string) getcwd();

afterEach(function () use ($makeHere): void {
    $here = $makeHere();

    chdir($here);
    Scratch::sweep();
});

/**
 * What `doctor --format=json` writes in a project, with a PHP that loads pcov.
 *
 * @param array<string, bool> $input
 */
function doctorJson(string $project, string $php, Measure $measure, array $input, Online $online): string
{
    $tester = new CommandTester(Doctor::command(
        Doctored::observed($project, sprintf('%s/php', $php)),
        $measure,
        $online,
        Guide::unreleased(),
    ));
    $tester->execute(['--format' => 'json', ...$input]);

    return $tester->getDisplay();
}

it('exits 1 where a finding would fail a run, and writes each as text', function (): void {
    [$code, $output, $errors] = DoctorRuns::over('tests/Fixtures/Projects/TwoRunners');

    expect([$code, $errors])->toBe([1, ''])
        ->and($output)->toStartWith("will fail  two-runners\n")
        ->and($output)->toEndWith("1 would fail a run, 0 would slow it, and 0 are advice.\n");
})->group(...$holds);

it('exits 0 where nothing would fail a run, and writes the public JSON when asked', function (): void {
    [$code, $output] = DoctorRuns::over('tests/Fixtures/Projects/Library', ['--format' => 'json']);
    $written = json_decode($output, associative: true);

    expect($code)->toBe(0)
        ->and($written)->toMatchArray(['format' => 1, 'failsARun' => false])
        ->and(Decoded::column($output, 'slug', 'findings'))->toBe(['tree-without-floor']);
})->group(...$holds);

it('finds no tree without a floor where the config declares the tree\'s floor', function (): void {
    [$code, $output] = DoctorRuns::over(
        'tests/Fixtures/Projects/Library',
        ['--format' => 'json'],
        ['mutation-gate.json' => '{"trees": [{"path": "src", "floor": 60}]}'],
    );

    expect($code)->toBe(0)
        ->and(Decoded::column($output, 'slug', 'findings'))->not->toContain('tree-without-floor');
})->group(...$holds);

it('runs the suite under --measure, and fails where measuring it fails', function (): void {
    $project = FlowCommands::project();
    Scratch::write($project, '.gitignore', ".mutation-gate/\n");
    $php = FakePhp::printing(Described::output(['pcov' => '1.0.12'], ['extension_dir' => '/nowhere']));
    $runner = new CoverageAsked(ScriptedRunner::fixture(), CannotJudge::because('1 test failed.'));
    $measure = new Measure(FlowCommands::composition($project, $runner, new ProofStoreFake(), Flows::ci()));
    $nowhere = new Online($project, Variables::of([]), GitHubAnswering::client([]));
    $offline = Decoded::column(doctorJson($project, $php, $measure, [], $nowhere), 'slug', 'findings');

    expect($runner->asked())->toBe([])
        ->and(Decoded::column(doctorJson($project, $php, $measure, ['--measure' => true], $nowhere), 'slug', 'findings'))
        ->toBe([...$offline, 'coverage-run-failed'])
        ->and($runner->asked())->toHaveCount(1);
})->group(...$holds);

it('reads GitHub\'s settings under --online, as advice that fails no run', function (): void {
    $project = FlowCommands::project();
    Scratch::write($project, '.gitignore', ".mutation-gate/\n");
    $php = FakePhp::printing(Described::output(['pcov' => '1.0.12'], ['extension_dir' => '/nowhere']));
    $measure = new Measure(FlowCommands::composition($project, ScriptedRunner::fixture(), new ProofStoreFake(), Flows::ci()));
    $nowhere = new Online($project, Variables::of([]), GitHubAnswering::client([]));
    $online = new Online($project, Variables::of(['GITHUB_REPOSITORY' => 'octo/gate']), GitHubAnswering::client([
        '/repos/octo/gate' => new JsonMockResponse(['default_branch' => 'main']),
        '/repos/octo/gate/rules/branches/main' => new JsonMockResponse([]),
        '/repos/octo/gate/actions/permissions/fork-pr-contributor-approval' => new JsonMockResponse(['approval_policy' => 'all_external_contributors']),
    ]));
    $offline = doctorJson($project, $php, $measure, [], $nowhere);
    $asked = doctorJson($project, $php, $measure, ['--online' => true], $online);

    expect(Decoded::column($asked, 'slug', 'findings'))
        ->toBe([...Decoded::column($offline, 'slug', 'findings'), 'online-unread', 'verdict-not-required'])
        ->and(Decoded::column($asked, 'severity', 'findings'))
        ->toBe([...Decoded::column($offline, 'severity', 'findings'), 'advice', 'advice']);
})->group(...$holds);
