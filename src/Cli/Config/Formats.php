<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use Closure;
use Nette\Neon\Neon;
use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Format;
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
    /** @param Closure(class-string): bool $installed whether a library's class can be loaded */
    public function __construct(private Closure $installed)
    {
    }

    /** The format `--format` names. */
    public static function chosen(string $given): Format|CannotJudge
    {
        return Format::tryFrom($given)
            ?? CannotJudge::because(sprintf('--format is php, json, yaml or neon, not "%s".', $given));
    }

    /** The loader that reads a config file, or what to install to read it. */
    public static function loader(Extensions $extensions, Path $file): ConfigLoader|CannotJudge
    {
        $extension = pathinfo($file->value(), PATHINFO_EXTENSION);
        $format = Format::fromExtension($extension);
        $name = $format instanceof Format ? $format->value : $extension;
        $loader = Lookup::in($extensions)->configLoader(Name::of($name), Options::none());

        return match (true) {
            $loader instanceof ConfigLoader => $loader,
            $loader instanceof Invalid => CannotJudge::because(
                sprintf('The %s config loader needs options, and nothing can give it any.', $name),
            ),
            $format instanceof Format && $format->isSuggested() => CannotJudge::because(sprintf(
                '%s is %s, which needs %s to be read. Install it: composer require --dev %s',
                $file->value(),
                $format->title(),
                $format->package(),
                $format->package(),
            )),
            default => CannotJudge::because(sprintf(
                'No config loader reads %s. Name a mutation-gate.php, .json, .yaml, .yml or .neon file.',
                $file->value(),
            )),
        };
    }

    /** A config written out as a person reads it. */
    public function render(Document $document, Format $format): string|CannotJudge
    {
        return match ($format) {
            Format::Json => sprintf("%s\n", $document->json()),
            Format::Php => Php::render($document),
            Format::Yaml => ($this->installed)(Yaml::class)
                ? new YamlConfig()->render($document)
                : $this->needs($format),
            Format::Neon => ($this->installed)(Neon::class)
                ? new NeonConfig()->render($document)
                : $this->needs($format),
        };
    }

    private function needs(Format $format): CannotJudge
    {
        return CannotJudge::because(sprintf(
            '--format=%s needs %s. Install it: composer require --dev %s',
            $format->value,
            $format->package(),
            $format->package(),
        ));
    }
}
