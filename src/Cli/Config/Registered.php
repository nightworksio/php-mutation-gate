<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use Closure;

use function getcwd;

use Nette\Neon\Neon;
use NightWorksIO\MutationGate\Adapter\Json\JsonConfig;
use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Php\PhpConfig;
use NightWorksIO\MutationGate\Adapter\Project\AutoloadTrees;
use NightWorksIO\MutationGate\Adapter\Project\PhpUnitTrees;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Extension\Extensions;
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
            ->withConfigLoader(Name::of(Format::Php->value), static fn(): ConfigLoader => new PhpConfig())
            ->withConfigLoader(Name::of(Format::Json->value), static fn(): ConfigLoader => new JsonConfig())
            ->withTreeSource(
                Name::of('phpunit'),
                static fn(Options $options): TreeSource|Invalid => self::phpunit($options),
            )
            ->withTreeSource(Name::of('composer'), static fn(): TreeSource => AutoloadTrees::in(self::here()));
        $withYaml = $installed(Yaml::class)
            ? $registry->withConfigLoader(Name::of(Format::Yaml->value), static fn(): ConfigLoader => new YamlConfig())
            : $registry;
        $withNeon = $installed(Neon::class)
            ? $withYaml->withConfigLoader(Name::of(Format::Neon->value), static fn(): ConfigLoader => new NeonConfig())
            : $withYaml;

        return Presets::registered($withNeon);
    }

    /** The `phpunit` tree source, with the `fallback` paths its options give, none where they give none. */
    private static function phpunit(Options $options): TreeSource|Invalid
    {
        $fallback = $options->paths(Key::of('fallback'));

        return $fallback instanceof Problem
            ? Invalid::because($fallback)
            : PhpUnitTrees::in(self::here(), $fallback instanceof Paths ? $fallback : Paths::none());
    }

    /** The working directory, where the project is. */
    private static function here(): string
    {
        return (string) getcwd();
    }
}
