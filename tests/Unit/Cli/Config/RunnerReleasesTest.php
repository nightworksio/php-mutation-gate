<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Codeception\Installed as CodeceptionReleases;
use NightWorksIO\MutationGate\Adapter\Infection\Installed as InfectionReleases;
use NightWorksIO\MutationGate\Adapter\Pest\Installed as PestReleases;
use NightWorksIO\MutationGate\Adapter\PhpSpec\Installed as PhpSpecReleases;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Installed as PhpUnitReleases;
use NightWorksIO\MutationGate\Cli\Config\RunnerReleases;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads the releases each built-in runner drives as that runner reads them', function (BuiltinRunner $runner, Closure $read): void {
    $project = Scratch::directory();
    Scratch::write($project, 'vendor/composer/installed.json', (string) json_encode(['packages' => [
        ['name' => 'phpunit/phpunit', 'version' => '13.4.5'],
        ['name' => 'phpunit/php-code-coverage', 'version' => '14.3.0'],
        ['name' => 'pestphp/pest', 'version' => '5.3.4'],
        ['name' => 'pestphp/pest-plugin-mutate', 'version' => '5.0.2'],
        ['name' => 'infection/infection', 'version' => '0.35.8'],
        ['name' => 'phpspec/phpspec', 'version' => '8.2.0'],
        ['name' => 'codeception/codeception', 'version' => '5.3.6'],
    ]]));
    $manifest = sprintf('%s/vendor/composer/installed.json', $project);

    expect(RunnerReleases::of($runner, $manifest))->toEqual($read($manifest));
})->with([
    'Pest' => [BuiltinRunner::Pest, static fn(string $manifest): mixed => PestReleases::versionsIn($manifest)],
    'Infection' => [BuiltinRunner::Infection, static fn(string $manifest): mixed => InfectionReleases::versionsIn($manifest)],
    'PHPUnit' => [BuiltinRunner::PhpUnit, static fn(string $manifest): mixed => PhpUnitReleases::versionsIn($manifest)],
    'PhpSpec' => [BuiltinRunner::PhpSpec, static fn(string $manifest): mixed => PhpSpecReleases::versionsIn($manifest)],
    'Codeception' => [BuiltinRunner::Codeception, static fn(string $manifest): mixed => CodeceptionReleases::versionsIn($manifest)],
]);
