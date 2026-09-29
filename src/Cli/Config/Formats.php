<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_key_exists;

use Closure;
use Nette\Neon\Neon;
use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\ConfigLoader;

use function pathinfo;
use function sprintf;

use Symfony\Component\Yaml\Yaml;

/**
 * The four formats a config is written in (ADR-0002): the loader that reads
 * each, and how `init` and `config:show` write each. YAML and NEON need a
 * library that is only suggested, and without it the gate says which one to
 * install.
 */
final readonly class Formats
{
    /** The formats that need a library, with what they are called and the package that reads them. */
    private const array OPTIONAL = [
        'yaml' => ['YAML', 'symfony/yaml'],
        'neon' => ['NEON', 'nette/neon'],
    ];

    /** @param Closure(class-string): bool $installed whether a library's class can be loaded */
    public function __construct(private Closure $installed)
    {
    }

    /** The format a config file is written in, named by its extension; `.yml` is YAML. */
    public static function of(Path $file): string
    {
        $extension = pathinfo($file->value(), PATHINFO_EXTENSION);

        return $extension === 'yml' ? 'yaml' : $extension;
    }

    /** The loader that reads a config file, or what to install to read it. */
    public static function loader(Extensions $extensions, Path $file): ConfigLoader|CannotJudge
    {
        $format = self::of($file);
        $loader = $extensions->configLoader(Name::of($format), Options::none());

        return match (true) {
            $loader instanceof ConfigLoader => $loader,
            $loader instanceof Invalid => CannotJudge::because(
                sprintf('The %s config loader needs options, and nothing can give it any.', $format),
            ),
            array_key_exists($format, self::OPTIONAL) => CannotJudge::because(sprintf(
                '%s is %s, which needs %s to be read. Install it: composer require --dev %s',
                $file->value(),
                self::OPTIONAL[$format][0],
                self::OPTIONAL[$format][1],
                self::OPTIONAL[$format][1],
            )),
            default => CannotJudge::because(sprintf(
                'No config loader reads %s. Name a mutation-gate.php, .json, .yaml, .yml or .neon file.',
                $file->value(),
            )),
        };
    }

    /** A config written out as a person reads it: `php`, `json`, `yaml` or `neon`. */
    public function render(Document $document, string $format): string|CannotJudge
    {
        return match ($format) {
            'json' => sprintf("%s\n", $document->json()),
            'php' => Php::render($document),
            'yaml' => ($this->installed)(Yaml::class) ? new YamlConfig()->render($document) : self::needs('yaml'),
            'neon' => ($this->installed)(Neon::class) ? new NeonConfig()->render($document) : self::needs('neon'),
            default => CannotJudge::because(sprintf('--format is php, json, yaml or neon, not "%s".', $format)),
        };
    }

    /** @param 'yaml'|'neon' $format */
    private static function needs(string $format): CannotJudge
    {
        return CannotJudge::because(sprintf(
            '--format=%s needs %s. Install it: composer require --dev %s',
            $format,
            self::OPTIONAL[$format][1],
            self::OPTIONAL[$format][1],
        ));
    }
}
