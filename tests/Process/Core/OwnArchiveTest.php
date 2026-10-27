<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The gate's PHAR, as composer phar builds it, copied to a file named as the image and Phive name it, with no
 * `.phar`, and run in a directory with these arguments, outside the CI this suite may run in.
 */
$archive = static function (string $directory, string ...$arguments): Process {
    $installed = sprintf('%s/mutation-gate', Scratch::directory());
    copy(Tree::at('build/mutation-gate.phar'), $installed);
    $ci = array_filter(
        getenv(),
        static fn(string $name): bool => $name === 'CI' || str_starts_with($name, 'GITHUB_'),
        ARRAY_FILTER_USE_KEY,
    );
    $process = new Process([PHP_BINARY, $installed, ...$arguments], $directory, array_map(static fn(): bool => false, $ci), timeout: 600);
    $process->run();

    return $process;
};

/**
 * A copy of the fixture, committed in a repository of its own, which installs no copy of the gate. The commit starts
 * no background gc, which would still be packing as the test's directory is swept.
 */
$project = static function (): string {
    $root = Scratch::copy('tests/Fixtures/Archive');

    foreach ([['init', '--quiet'], ['add', '--all'], ['-c', 'user.name=gate', '-c', 'user.email=gate@example.com', '-c', 'commit.gpgsign=false', '-c', 'gc.auto=0', '-c', 'maintenance.auto=false', 'commit', '--quiet', '--message=fixture']] as $git) {
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

it('names itself as it was started in the hook it installs, from the project\'s root', function () use ($project): void {
    $root = $project();
    Scratch::write($root, 'tools/.keep', '');
    copy(Tree::at('build/mutation-gate.phar'), sprintf('%s/tools/mutation-gate', $root));
    $install = new Process([PHP_BINARY, 'tools/mutation-gate', 'hook', 'install'], $root, timeout: 600);
    $install->run();

    expect($install->getExitCode())->toBe(0, sprintf('%s%s', $install->getOutput(), $install->getErrorOutput()))
        ->and((string) file_get_contents(sprintf('%s/.git/hooks/pre-push', $root)))
        ->toEndWith("exec php tools/mutation-gate pre-push \"\$@\"\n");
})->group('phar');
