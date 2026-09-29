<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_key_exists;

use Closure;

use function getcwd;
use function is_array;
use function is_string;

use Nette\Neon\Neon;
use NightWorksIO\MutationGate\Adapter\Json\JsonConfig;
use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Php\PhpConfig;
use NightWorksIO\MutationGate\Adapter\Project\AutoloadTrees;
use NightWorksIO\MutationGate\Adapter\Project\PhpUnitTrees;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Core\Config\Definition\Json;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\ConfigLoader;
use NightWorksIO\MutationGate\Port\TreeSource;
use Symfony\Component\Yaml\Yaml;

/**
 * This package's config adapters and presets, as its first-party extension
 * registers them: the four config formats, YAML and NEON only where their
 * library is installed, the `phpunit` and `composer` tree sources, and the
 * `library`, `laravel` and `symfony` presets.
 */
final readonly class Registered
{
    /** @param Closure(class-string): bool $installed whether a library's class can be loaded */
    public static function config(Extensions $extensions, Closure $installed): Extensions
    {
        $registry = $extensions
            ->withConfigLoader(Name::of('php'), static fn(): ConfigLoader => new PhpConfig())
            ->withConfigLoader(Name::of('json'), static fn(): ConfigLoader => new JsonConfig())
            ->withTreeSource(
                Name::of('phpunit'),
                static fn(Options $options): TreeSource => PhpUnitTrees::in(self::here(), self::fallback($options)),
            )
            ->withTreeSource(Name::of('composer'), static fn(): TreeSource => AutoloadTrees::in(self::here()));
        $withYaml = $installed(Yaml::class)
            ? $registry->withConfigLoader(Name::of('yaml'), static fn(): ConfigLoader => new YamlConfig())
            : $registry;
        $withNeon = $installed(Neon::class)
            ? $withYaml->withConfigLoader(Name::of('neon'), static fn(): ConfigLoader => new NeonConfig())
            : $withYaml;

        return self::presets($withNeon);
    }

    private static function presets(Extensions $registry): Extensions
    {
        $presets = $registry;

        $shipped = ['library' => Presets::library(), 'laravel' => Presets::laravel(), 'symfony' => Presets::symfony()];

        foreach ($shipped as $name => $preset) {
            $presets = $preset instanceof Document ? $presets->withPreset(Name::of($name), $preset) : $presets;
        }

        return $presets;
    }

    /** The `fallback` paths a `phpunit` tree source's options give. */
    private static function fallback(Options $options): Paths
    {
        $decoded = Json::decode($options->json());
        $fallback = is_array($decoded) && array_key_exists('fallback', $decoded) && is_array($decoded['fallback'])
            ? $decoded['fallback']
            : [];
        $paths = Paths::none();

        foreach ($fallback as $path) {
            $paths = is_string($path) ? $paths->with(Path::of($path)) : $paths;
        }

        return $paths;
    }

    /** The working directory, where the project is. */
    private static function here(): string
    {
        return (string) getcwd();
    }
}
