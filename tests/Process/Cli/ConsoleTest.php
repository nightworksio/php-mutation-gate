<?php

declare(strict_types=1);
use NightWorksIO\MutationGate\Tests\Support\Commands;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Printed;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Console\Tester\ApplicationTester;

$holds = [
    'holds:src/Cli/Command/Doctor.php',
    'holds:src/Cli/Command/FormOption.php',
    'holds:src/Cli/Console.php',
];

it('says what would fail a run in a project, before a run does', function (): void {
    $project = Scratch::copy('tests/Fixtures/Projects/TwoRunners');
    $tester = new ApplicationTester(Commands::console($project));
    $code = $tester->run(['command' => 'doctor', '--format' => 'json']);
    $slugs = Decoded::column(Printed::by($tester->getOutput()), 'slug', 'findings');

    expect($code)->toBe(1)
        ->and($slugs)->toContain('two-runners');
})->group(...$holds);
