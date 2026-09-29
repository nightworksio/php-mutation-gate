<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Discovery\Discovery;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Tests\Fakes\ExtensionFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A manifest naming these extension classes, as a package or as the root.
 *
 * @param list<string> $classes
 */
$manifest = static fn(string $name, array $classes): array => ['name' => $name, 'extra' => ['mutation-gate' => ['extensions' => $classes]]];

/**
 * The discovery over a project whose root and installed packages declare these manifests.
 *
 * @param array<string, mixed>|string       $root
 * @param list<array<string, mixed>>|string $installed
 */
$discovery = static function (array|string $root, array|string $installed): Discovery {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', is_string($root) ? $root : (string) json_encode($root));
    Scratch::write($project, 'vendor/composer/installed.json', is_string($installed) ? $installed : (string) json_encode(['packages' => $installed]));

    return new Discovery(Directory::at($project), Directory::at(sprintf('%s/vendor', $project)));
};

$runs = static fn(Extensions|CannotJudge $registry, string $name): bool => $registry instanceof Extensions && $registry->runner(Name::of($name), Options::none()) instanceof Runner;

it('registers what the root and every installed package declare', function () use ($discovery, $manifest, $runs): void {
    $registry = $discovery($manifest(FirstParty::PACKAGE, [FirstParty::class]), [$manifest('acme/one', [ExtensionFake::class])])->extensions(firstPartyOnly: false);

    expect($registry)->toBeInstanceOf(Extensions::class)
        ->and($runs($registry, 'fake'))->toBeTrue();
});

it('registers only this package\'s own extension when asked to', function () use ($discovery, $manifest, $runs): void {
    $registry = $discovery($manifest(FirstParty::PACKAGE, [FirstParty::class]), [$manifest('acme/one', [ExtensionFake::class])])->extensions(firstPartyOnly: true);

    expect($registry)->toBeInstanceOf(Extensions::class)
        ->and($runs($registry, 'fake'))->toBeFalse();
});

it('registers this package\'s own extension when it is installed as a dependency', function () use ($discovery, $manifest, $runs): void {
    $registry = $discovery($manifest('acme/app', []), [$manifest(FirstParty::PACKAGE, [ExtensionFake::class])])->extensions(firstPartyOnly: true);

    expect($runs($registry, 'fake'))->toBeTrue();
});

it('registers nothing where there is no manifest', function () use ($runs): void {
    $project = Scratch::directory();
    $registry = new Discovery(Directory::at($project), Directory::at(sprintf('%s/vendor', $project)))->extensions(firstPartyOnly: false);

    expect($registry)->toBeInstanceOf(Extensions::class)
        ->and($runs($registry, 'fake'))->toBeFalse();
});

it('cannot judge two packages that register the same name', function () use ($discovery, $manifest): void {
    expect($discovery($manifest('acme/app', []), [$manifest('acme/one', [ExtensionFake::class]), $manifest('acme/two', [ExtensionFake::class])])->extensions(firstPartyOnly: false))
        ->toEqual(CannotJudge::because('Two packages register a runner named "fake": acme/one and acme/two. Remove one of the packages, or run with --no-extensions.'));
});

it('cannot judge a declared class that is not an extension, and loads nothing after it', function (string $class) use ($discovery, $manifest): void {
    expect($discovery($manifest('acme/app', [$class, ExtensionFake::class]), [])->extensions(firstPartyOnly: false))
        ->toEqual(CannotJudge::because(sprintf('acme/app names %s as a mutation-gate extension, and it is not a class that implements NightWorksIO\\MutationGate\\Extension\\Extension.', $class)));
})->with(['stdClass', 'Acme\\Nowhere\\Extension']);

it('cannot judge a manifest it cannot read, the root before the installed packages', function () use ($discovery): void {
    expect($discovery('not json', 'not json either')->extensions(firstPartyOnly: false))
        ->toEqual(CannotJudge::because('composer.json is not a JSON object, so the extensions it names cannot be read.'))
        ->and($discovery('{}', 'not json either')->extensions(firstPartyOnly: false))
        ->toEqual(CannotJudge::because('composer/installed.json is not the list of installed packages Composer 2 writes, so their extensions cannot be read.'));
});

it('cannot judge a manifest that is a directory', function (): void {
    $project = Scratch::directory();
    mkdir(sprintf('%s/composer.json', $project));

    expect(new Discovery(Directory::at($project), Directory::at($project))->extensions(firstPartyOnly: false))
        ->toEqual(CannotJudge::because(sprintf('%s/composer.json could not be read.', $project)));
});
