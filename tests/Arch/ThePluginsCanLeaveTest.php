<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use NightWorksIO\MutationGate\Core\Registry\FirstPartyPackage;
use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Support\Api;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Layer;
use NightWorksIO\MutationGate\Tests\Support\Source;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// A6–A8, over each plugin under plugins/: a first-party set that is a package
// in all but publication, and leaves by moving its directory (ADR-0021).

/** The package this repository's own code is. */
const THE_GATE = 'nightworksio/mutation-gate';

/** The package the mutator SDK is written against. */
const THE_PARSER = 'nikic/php-parser';

/**
 * Each plugin's namespace prefixes, its code's and its tests', by the plugin's
 * directory.
 *
 * @return array<string, list<string>>
 */
function pluginNamespaces(): array
{
    $found = [];

    foreach (Tree::plugins() as $plugin) {
        $found[$plugin] = [];

        foreach (Tree::namespaces() as $directory => $namespace) {
            if (str_starts_with($directory, sprintf('%s/', $plugin))) {
                $found[$plugin][] = $namespace;
            }
        }
    }

    return $found;
}

/** @param list<string> $prefixes */
function startsWithAny(string $name, array $prefixes): bool
{
    return array_any($prefixes, static fn(string $prefix): bool => str_starts_with($name, $prefix));
}

/** The package a plugin's composer.json names. */
function pluginPackage(string $plugin): string
{
    $name = Decoded::at((string) file_get_contents(Tree::at(sprintf('%s/composer.json', $plugin))), 'name');

    return is_string($name) ? $name : '';
}

/**
 * The package a class is loaded from: this repository's own, a plugin's, or
 * one under vendor; nothing for a class of PHP itself.
 */
function packageOf(string $class): string
{
    foreach (ClassLoader::getRegisteredLoaders() as $loader) {
        $file = $loader->findFile($class);

        if (is_string($file)) {
            $path = str_replace(sprintf('%s/', realpath(Tree::root())), '', (string) realpath($file));

            return match (true) {
                str_starts_with($path, 'vendor/') => implode('/', array_slice(explode('/', $path), 1, 2)),
                str_starts_with($path, 'plugins/') => pluginPackage(sprintf('plugins/%s', explode('/', $path)[1])),
                default => THE_GATE,
            };
        }
    }

    return '';
}

it('reads at least one plugin', function (): void {
    expect(Tree::plugins())->not->toBe([], 'no plugin was read, so every rule in this file judged nothing');
});

it('keeps src from naming any plugin', function (): void {
    $prefixes = array_merge(...array_values(pluginNamespaces()));
    $offenders = [];

    foreach (Source::under('src') as $source) {
        foreach ($source->names() as $name) {
            if (startsWithAny($name, $prefixes)) {
                $offenders[] = sprintf('%s names %s', $source->path, $name);
            }
        }
    }

    // A6
    expect($offenders)->toBe([], sprintf(
        "These name a plugin:\n  %s\n\nA plugin leaves by moving its directory to a repository of its own, and code that names it would break the day it does. Register what it offers through its extension instead (A6).",
        implode("\n  ", $offenders),
    ));
});

it('lets a plugin name only PHP, php-parser, the mutator SDK, the extension API and the core values they reach', function (): void {
    $reached = array_values(array_filter(
        array_map(static fn(ReflectionClass $class): string => $class->getName(), Api::surface()),
        Layer::Core->holds(...),
    ));
    $allowed = [...$reached, Extension::class, Extensions::class];
    $offenders = [];

    foreach (pluginNamespaces() as $plugin => $own) {
        foreach ([...Source::under(sprintf('%s/src', $plugin)), ...Source::under(sprintf('%s/tests', $plugin))] as $source) {
            foreach ($source->names() as $name) {
                $fine = ! str_contains($name, '\\')
                    || startsWithAny($name, [...$own, 'PhpParser\\', sprintf('%s\\', Layer::Mutator->namespace())])
                    || in_array($name, $allowed, strict: true);

                if (! $fine) {
                    $offenders[] = sprintf('%s names %s', $source->path, $name);
                }
            }
        }
    }

    // A7
    expect($offenders)->toBe([], sprintf(
        "These plugin files name something a plugin may not:\n  %s\n\nA plugin is written against the public SDK alone, so it keeps working when it leaves this repository. Name the mutator SDK, the extension API or a core value their signatures reach, and never another plugin or the gate's internals (A7).",
        implode("\n  ", $offenders),
    ));
});

it('gives each plugin a manifest of its own that requires every package its code names', function (): void {
    $root = (string) file_get_contents(Tree::at('composer.json'));
    $offenders = [];

    foreach (pluginNamespaces() as $plugin => $own) {
        $manifest = (string) file_get_contents(Tree::at(sprintf('%s/composer.json', $plugin)));
        $name = pluginPackage($plugin);
        $required = Decoded::at($manifest, 'require');
        $required = is_array($required) ? array_keys($required) : [];

        if ($name !== sprintf('%s-%s', THE_GATE, basename($plugin))) {
            $offenders[] = sprintf('%s/composer.json is not named %s-%s', $plugin, THE_GATE, basename($plugin));
        }

        if (! FirstPartyPackage::tryFrom($name) instanceof FirstPartyPackage) {
            $offenders[] = sprintf('%s is not listed in %s, so --no-extensions would leave it out', $name, FirstPartyPackage::class);
        }

        foreach ([THE_GATE, THE_PARSER] as $package) {
            if (! in_array($package, $required, strict: true)) {
                $offenders[] = sprintf('%s/composer.json does not require %s', $plugin, $package);
            }
        }

        foreach ($own as $namespace) {
            $mapped = sprintf('%s/%s/', $plugin, str_contains($namespace, '\\Tests\\') ? 'tests' : 'src');
            $autoload = str_contains($namespace, '\\Tests\\') ? 'autoload-dev' : 'autoload';

            if (Decoded::at($manifest, $autoload, 'psr-4', $namespace) !== mb_substr($mapped, mb_strlen($plugin) + 1)) {
                $offenders[] = sprintf('%s/composer.json does not map %s to %s', $plugin, $namespace, $mapped);
            }
        }

        $extensions = Decoded::at($manifest, 'extra', 'mutation-gate', 'extensions');
        $listed = Decoded::at($root, 'extra', 'mutation-gate', 'extensions');

        foreach (is_array($extensions) && $extensions !== [] ? $extensions : [null] as $extension) {
            if (! is_string($extension) || ! is_array($listed) || ! in_array($extension, $listed, strict: true)) {
                $offenders[] = sprintf('%s lists no extension the root composer.json lists too', $plugin);
            }
        }

        foreach (Source::under(sprintf('%s/src', $plugin)) as $source) {
            foreach ($source->names() as $named) {
                $package = packageOf($named);

                if ($package !== '' && $package !== $name && ! in_array($package, $required, strict: true)) {
                    $offenders[] = sprintf('%s names %s, from %s, which %s/composer.json does not require', $source->path, $named, $package, $plugin);
                }
            }
        }
    }

    // A8
    expect($offenders)->toBe([], sprintf(
        "These plugins could not leave as they are:\n  %s\n\nA plugin leaves by moving its directory, and its composer.json is then all it has: its name, its autoloading, its extension and every package its code names. While it is here, its name is listed as first party (A8).",
        implode("\n  ", $offenders),
    ));
});
