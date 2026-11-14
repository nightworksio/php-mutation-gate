<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\PackageDirectory;
use NightWorksIO\MutationGate\Adapter\Pest\GatePlugins;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Cli\PestPlugin;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Registry\FirstPartyPackage;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Plugins\Bail;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A project whose Pest lists these plugins, with this manifest of its own and these packages installed.
 *
 * @param list<string>|string        $listed
 * @param array<mixed>              $root
 * @param list<array<string, mixed>>  $installed
 */
function pestListing(array|string $listed, array $root, array $installed = []): Project
{
    $directory = (string) realpath(Scratch::directory());
    Scratch::write($directory, 'vendor/pest-plugins.json', is_string($listed) ? $listed : (string) json_encode($listed));
    Scratch::write($directory, 'composer.json', (string) json_encode($root));
    Scratch::write($directory, 'vendor/composer/installed.json', (string) json_encode(['packages' => $installed]));

    return Project::at($directory, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));
}

/**
 * A package of this name that autoloads this namespace.
 *
 * @return array<string, mixed>
 */
function autoloading(string $name, string $namespace): array
{
    return ['name' => $name, 'version' => '1.0.0', 'type' => 'library', 'autoload' => ['psr-4' => [$namespace => 'src/']]];
}

it('lists the plugins Pest lists that the gate the project is declares, and no other', function (): void {
    $project = pestListing(
        ['Acme\\Pest\\Plugin', PestPlugin::class, Bail::class],
        autoloading(ThisPackage::COMPOSER, 'NightWorksIO\\MutationGate\\'),
        [autoloading('acme/pest-plugin', 'Acme\\Pest\\')],
    );

    expect([...GatePlugins::listedIn($project, new PackageDirectory())->names()])->toBe([PestPlugin::class]);
});

it('lists the plugin of a first-party package the project installs, and none of a root that is no first-party package', function (): void {
    $project = pestListing(
        [PestPlugin::class, 'Acme\\App\\Plugin'],
        autoloading('acme/app', 'Acme\\App\\'),
        [autoloading(FirstPartyPackage::Gate->value, 'NightWorksIO\\MutationGate\\')],
    );

    expect([...GatePlugins::listedIn($project, new PackageDirectory())->names()])->toBe([PestPlugin::class]);
});

it('lists the plugin of each first-party package the project installs', function (): void {
    $project = pestListing(
        [PestPlugin::class, 'NightWorksIO\\Laravel\\Plugin'],
        autoloading('acme/app', 'Acme\\App\\'),
        [
            autoloading(FirstPartyPackage::Gate->value, 'NightWorksIO\\MutationGate\\'),
            autoloading(FirstPartyPackage::LaravelSet->value, 'NightWorksIO\\Laravel\\'),
        ],
    );

    expect([...GatePlugins::listedIn($project, new PackageDirectory())->names()])->toBe([PestPlugin::class, 'NightWorksIO\\Laravel\\Plugin']);
});

it('lists none where Pest\'s list, or a manifest, cannot be read as one', function (string $listed, array $root): void {
    expect(count(GatePlugins::listedIn(pestListing($listed, $root), new PackageDirectory())->names()))->toBe(0);
})->with([
    'a list that is not JSON' => ['not json', ['name' => ThisPackage::COMPOSER, 'autoload' => ['psr-4' => ['NightWorksIO\\MutationGate\\' => 'src/']]]],
    'a list that is not a list' => ['{"a": 1}', ['name' => ThisPackage::COMPOSER, 'autoload' => ['psr-4' => ['NightWorksIO\\MutationGate\\' => 'src/']]]],
    'a list of no names' => ['[1, ""]', ['name' => ThisPackage::COMPOSER, 'autoload' => ['psr-4' => ['NightWorksIO\\MutationGate\\' => 'src/']]]],
    'a root of no name' => ['["NightWorksIO\\\\MutationGate\\\\Cli\\\\PestPlugin"]', ['autoload' => ['psr-4' => ['NightWorksIO\\MutationGate\\' => 'src/']]]],
]);
