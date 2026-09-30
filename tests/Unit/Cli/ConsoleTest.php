<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Commands;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Printed;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

$console = static fn(): Application => Commands::console(Tree::root());

it('is the mutation-gate command line', function () use ($console): void {
    expect($console()->getName())->toBe('mutation-gate');
});

it('offers every command the README lists', function (string $command) use ($console): void {
    expect($console()->has($command))->toBeTrue();
})->with([
    'run',
    'plan',
    'verdict',
    'baseline',
    'coverage',
    'reproduce',
    'triage',
    'watch',
    'pre-push',
    'hook',
    'init',
    'import',
    'config:show',
    'config:schema',
    'pest:patch',
    'doctor',
]);

it('says what would fail a run in a project, before a run does', function (): void {
    $project = Scratch::copy('tests/Fixtures/Projects/TwoRunners');
    $tester = new ApplicationTester(Commands::console($project));
    $code = $tester->run(['command' => 'doctor', '--format' => 'json']);
    $slugs = Decoded::column(Printed::by($tester->getOutput()), 'slug', 'findings');

    expect($code)->toBe(1)
        ->and($slugs)->toContain('two-runners');
});

it('runs the whole gate when no command is named, beginning with the config', function () use ($console): void {
    $tester = new ApplicationTester($console());

    expect($tester->run([]))->toBe(2)
        ->and(Printed::by($tester->getOutput()))
        ->toBe("Both pestphp/pest-plugin-mutate and infection/infection are installed. Choose one: set runner in the config, or pass --runner.\n");
});

it('accepts --no-extensions before any command, as a flag', function () use ($console): void {
    $options = $console()->getDefinition()->getOptions();

    expect(array_key_exists('no-extensions', $options))->toBeTrue()
        ->and(array_key_exists('no-extensions', $options) && $options['no-extensions']->acceptValue())->toBeFalse();
});

it('takes the config file, the runner, the reports, the budget and the CI on any command', function () use (
    $console,
): void {
    $definition = $console()->getDefinition();

    expect($definition->getOption('config')->isValueRequired())->toBeTrue()
        ->and($definition->getOption('runner')->isValueRequired())->toBeTrue()
        ->and($definition->getOption('report')->isArray())->toBeTrue()
        ->and($definition->getOption('report')->isValueRequired())->toBeTrue()
        ->and($definition->getOption('budget')->isValueRequired())->toBeTrue()
        ->and($definition->getOption('ci')->isValueRequired())->toBeTrue();
});

it('builds the config commands', function () use ($console): void {
    $tester = new ApplicationTester($console());

    expect($tester->run(['command' => 'config:schema']))->toBe(0)
        ->and(Printed::by($tester->getOutput()))
        ->toBe((string) file_get_contents(Tree::at('resources/mutation-gate.schema.json')));
});

it('answers with an exit code rather than ending the process', function () use ($console): void {
    expect($console()->isAutoExitEnabled())->toBeFalse();
});

it('patches Pest in the vendor directory Composer installed the project into', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '{"config": {"vendor-dir": "lib/vendor"}}');

    foreach (['MutationTest.php', 'Plugins/Mutate.php', 'Tester/MutationTestRunner.php'] as $file) {
        $installed = (string) file_get_contents(Tree::at(sprintf('vendor/pestphp/pest-plugin-mutate/src/%s', $file)));
        Scratch::write($project, sprintf('lib/vendor/pestphp/pest-plugin-mutate/src/%s', $file), $installed);
    }

    expect(Commands::run($project, 'pest:patch')->output)
        ->toBe("pest:patch patched 3 of the 3 files it changes in pest-plugin-mutate.\n");
});
