<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

/** The gate's PHAR, as composer phar builds it, run in a directory with these arguments. */
$archive = static function (string $directory, string ...$arguments): Process {
    $process = new Process([PHP_BINARY, Tree::at('build/mutation-gate.phar'), ...$arguments], $directory, timeout: 600);
    $process->run();

    return $process;
};

/** A copy of the fixture, committed in a repository of its own, which installs no copy of the gate. */
$project = static function (): string {
    $root = Scratch::copy('tests/Fixtures/Archive');

    foreach ([['init', '--quiet'], ['add', '--all'], ['-c', 'user.name=gate', '-c', 'user.email=gate@example.com', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '--message=fixture']] as $git) {
        new Process(['git', ...$git], $root)->mustRun();
    }

    return $root;
};

it('says its version, the one Composer gave the checkout it was built from', function () use ($archive): void {
    $version = $archive(Scratch::directory(), '--version');

    expect($version->getExitCode())->toBe(0)
        ->and($version->getOutput())->toMatch('/^mutation-gate \S+ [0-9a-f]{40}\n$/');
})->group('phar');

it('prints the config\'s JSON Schema as the committed one', function () use ($archive): void {
    $schema = $archive(Scratch::directory(), 'config:schema');

    expect($schema->getExitCode())->toBe(0)
        ->and($schema->getOutput())->toBe((string) file_get_contents(Tree::at('resources/mutation-gate.schema.json')));
})->group('phar');

it('judges an Infection project that installs no copy of the gate, killing every mutant', function () use ($archive, $project): void {
    $root = $project();
    $run = $archive($root, 'run');
    $report = json_decode((string) file_get_contents(sprintf('%s/.mutation-gate/report.json', $root)), associative: true);

    expect($run->getExitCode())->toBe(0, sprintf('%s%s', $run->getOutput(), $run->getErrorOutput()))
        ->and($run->getOutput())->toContain('src scores 100.00% against its floor of 100.00%.')
        ->and(is_array($report) ? $report['judgement'] : null)->toBe('passed')
        ->and(is_array($report) && is_array($report['counts']) ? $report['counts']['killed'] : 0)->toBeGreaterThan(0);
})->group('phar');

it('refuses the Pest runner, which only the Composer package can run', function () use ($archive, $project): void {
    $root = $project();
    file_put_contents(sprintf('%s/mutation-gate.json', $root), '{"runner": "pest"}');
    $run = $archive($root, 'run');

    expect($run->getExitCode())->toBe(2)
        ->and($run->getErrorOutput())->toContain('Pest runs the gate\'s plugin, which only the Composer package installs: composer require --dev nightworksio/mutation-gate -W');
})->group('phar');
