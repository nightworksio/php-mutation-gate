<?php

declare(strict_types=1);

use Nette\Neon\Neon;
use NightWorksIO\MutationGate\Adapter\Json\JsonConfig;
use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Php\PhpConfig;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Cli\Config\Presets;
use NightWorksIO\MutationGate\Cli\Config\Registered;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Extension\Origin;
use NightWorksIO\MutationGate\Port\TreeSource;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Yaml\Yaml;

$here = (string) getcwd();

afterEach(function () use ($here): void {
    chdir($here);
    Scratch::sweep();
});

/** This package's config adapters, with YAML and NEON installed or not. */
$registered = static fn(bool $installed): Extensions => Registered::config(
    new Extensions(Origin::of('nightworksio/mutation-gate')),
    static fn(): bool => $installed,
);

/** @return list<string>|CannotJudge the path of every tree the source finds in the working directory */
$paths = static function (TreeSource|Invalid|CannotJudge $source): array|CannotJudge|Invalid {
    $trees = $source instanceof TreeSource ? $source->trees() : $source;

    return $trees instanceof Trees
        ? array_map(static fn(Tree $tree): string => $tree->path()->value(), [...$trees])
        : $trees;
};

it('registers the PHP and JSON loaders whatever is installed', function () use ($registered): void {
    expect(Lookup::in($registered(installed: false))->configLoader(Name::of('php'), Options::none()))
        ->toBeInstanceOf(PhpConfig::class)
        ->and(Lookup::in($registered(installed: false))->configLoader(Name::of('json'), Options::none()))
        ->toBeInstanceOf(JsonConfig::class);
});

it('registers the YAML and NEON loaders where their library is installed', function () use ($registered): void {
    expect(Lookup::in($registered(installed: true))->configLoader(Name::of('yaml'), Options::none()))
        ->toBeInstanceOf(YamlConfig::class)
        ->and(Lookup::in($registered(installed: true))->configLoader(Name::of('neon'), Options::none()))
        ->toBeInstanceOf(NeonConfig::class);
});

it('registers no YAML or NEON loader where their library is missing', function () use ($registered): void {
    expect(Lookup::in($registered(installed: false))->configLoader(Name::of('yaml'), Options::none()))
        ->toEqual(CannotJudge::because('No config loader is registered as "yaml".'))
        ->and(Lookup::in($registered(installed: false))->configLoader(Name::of('neon'), Options::none()))
        ->toEqual(CannotJudge::because('No config loader is registered as "neon".'));
});

it('asks whether the class each library reads with can be loaded', function (): void {
    $asked = [];
    Registered::config(
        new Extensions(Origin::of('nightworksio/mutation-gate')),
        static function (string $class) use (&$asked): bool {
            $asked[] = $class;

            return true;
        },
    );

    expect($asked)->toBe([Yaml::class, Neon::class]);
});

it('registers the presets this package ships', function (string $preset, Closure $shipped) use ($registered): void {
    expect(Lookup::in($registered(installed: false))->preset(Name::of($preset)))->toEqual($shipped());
})->with([
    'library' => ['library', Presets::library(...)],
    'laravel' => ['laravel', Presets::laravel(...)],
    'symfony' => ['symfony', Presets::symfony(...)],
]);

it('finds the trees of the working directory from phpunit.xml, or the fallback its options give', function () use (
    $registered,
    $paths,
): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '{"autoload": {"psr-4": {"Acme\\\\": "src/"}}}');
    chdir($project);
    $phpunit = static fn(string $options): TreeSource|Invalid|CannotJudge => Lookup::in($registered(installed: false))
        ->treeSource(Name::of('phpunit'), Options::ofJson($options));

    expect($paths($phpunit('{}')))->toBe(['src'])
        ->and($paths($phpunit('{"fallback": ["lib"]}')))->toBe(['lib'])
        ->and($paths($phpunit('{"fallback": "lib"}')))->toBe(['src'])
        ->and($paths($phpunit('{"fallback": [3, "lib"]}')))->toBe(['lib'])
        ->and($paths($phpunit('"phpunit"')))->toBe(['src']);
});

it('finds the trees of the working directory from the autoload of composer.json', function () use (
    $registered,
    $paths,
): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '{"autoload": {"psr-4": {"Acme\\\\": "lib/"}}}');
    Scratch::write(
        $project,
        'phpunit.xml',
        '<phpunit><source><include><directory>src</directory></include></source></phpunit>',
    );
    chdir($project);

    expect($paths(Lookup::in($registered(installed: false))->treeSource(Name::of('composer'), Options::none())))->toBe(['lib']);
});
