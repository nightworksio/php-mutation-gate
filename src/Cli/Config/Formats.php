<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use Closure;
use Nette\Neon\Neon;
use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\ConfigLoader;

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
    /** The JSON Schema a config file names, where the package is installed in the project. */
    private const string SCHEMA = 'vendor/nightworksio/mutation-gate/resources/mutation-gate.schema.json';

    /** @param Closure(class-string): bool $installed whether a library's class can be loaded */
    public function __construct(private Closure $installed)
    {
    }

    /** The format `--format` names. */
    public static function chosen(string $given): Format|CannotJudge
    {
        return Format::tryFrom($given)
            ?? CannotJudge::because(sprintf('--format is %s, not "%s".', Format::words(), $given));
    }

    /** The loader that reads a config file, or what to install to read it. */
    public static function loader(Extensions $extensions, ConfigFile $file): ConfigLoader|CannotJudge
    {
        $extension = $file->extension();
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
                $file->file()->value(),
                $format->title(),
                $format->package(),
                $format->package(),
            )),
            default => CannotJudge::because(sprintf(
                'No config loader reads %s. Name a mutation-gate.php, .json, .yaml, .yml or .neon file.',
                $file->file()->value(),
            )),
        };
    }

    /** A layer of config written out as a person reads it, its paths named from this origin. */
    public function render(Layer $layer, Format $format, PathOrigin $origin): string|CannotJudge
    {
        return $this->rendered($layer, $layer->written($origin), $format, $origin);
    }

    /**
     * A layer of config written out as a config file, its paths named from the file's own directory: as JSON, it
     * names the JSON Schema an editor checks it with.
     */
    public function file(Layer $layer, Format $format, ConfigFile $file): string|CannotJudge
    {
        $written = $layer->written($file);
        $schema = Json::object(Member::of('$schema', $file->written(Path::of(self::SCHEMA))));

        return $this->rendered($layer, $format === Format::Json ? $schema->merged($written) : $written, $format, $file);
    }

    private function rendered(Layer $layer, Json $written, Format $format, PathOrigin $origin): string|CannotJudge
    {
        return match ($format) {
            Format::Json => sprintf("%s\n", $written->pretty()),
            Format::Php => Php::render($layer->php($origin)),
            Format::Yaml => ($this->installed)(Yaml::class)
                ? new YamlConfig()->render($written)
                : $this->needs($format),
            Format::Neon => ($this->installed)(Neon::class)
                ? new NeonConfig()->render($written)
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
