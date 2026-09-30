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
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
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
            ->withConfigLoader(Name::of(Format::Php->value), static fn(): ConfigLoader => new PhpConfig())
            ->withConfigLoader(Name::of(Format::Json->value), static fn(): ConfigLoader => new JsonConfig())
            ->withTreeSource(
                Name::of('phpunit'),
                static fn(Options $options): TreeSource => PhpUnitTrees::in(self::here(), self::fallback($options)),
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

    /** The `fallback` paths a `phpunit` tree source's options give. */
    private static function fallback(Options $options): Paths
    {
        $fallback = Node::config($options->json())->field('fallback');
        $paths = [];

        foreach ($fallback->kind() === Kind::List ? $fallback->items() : [] as $path) {
            if ($path->kind() === Kind::Text) {
                $paths[] = Path::of($path->text());
            }
        }

        return Paths::of(...$paths);
    }

    /** The working directory, where the project is. */
    private static function here(): string
    {
        return (string) getcwd();
    }
}
