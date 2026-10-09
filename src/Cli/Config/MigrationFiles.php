<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use Closure;

use function file_get_contents;
use function is_file;
use function is_string;

use Nette\Neon\Neon;
use NightWorksIO\MutationGate\Adapter\Json\JsonConfig;
use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Php\BaselineCall;
use NightWorksIO\MutationGate\Adapter\Php\PhpMigration;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Floors;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Migration\Migrated;
use NightWorksIO\MutationGate\Core\Migration\Migrating;
use NightWorksIO\MutationGate\Core\Migration\Migrations;
use NightWorksIO\MutationGate\Core\NotGiven;

use function pathinfo;
use function sprintf;

use Symfony\Component\Yaml\Yaml;

/**
 * The files `migrate` moves forward (ADR-0026, decisions 4 and 5): the
 * config, in the format its extension names, and the baseline the config
 * names, or the one at its standard path while the config does not read.
 * It runs nothing of the project's: a PHP config is parsed, never
 * included, and a data format is read by the gate's own loader, so no
 * extension's loader runs either.
 */
final readonly class MigrationFiles
{
    private const string UNREAD = '%s could not be read.';

    private const string NO_FORMAT
        = '%s is no config migrate can read. Name a mutation-gate.php, .json, .yaml, .yml or .neon file.';

    private const string NEEDS = '%s is %s, which needs %s to be migrated. Install it: composer require --dev %s';

    /** @param Closure(class-string): bool $installed whether a library's class can be loaded */
    public function __construct(private string $project, private Closure $installed)
    {
    }

    /** The config as `migrate` would write it; none where the project has no config file; or why it cannot. */
    public function config(CommandLine $given, Migrations $migrations): Migrated|NoConfigFile|CannotJudge
    {
        $path = ConfigLocation::in($this->project, $given->config);

        if (! $path instanceof Path) {
            return $path;
        }

        $shown = Root::of($this->project)->relative($path->value())->value();
        $text = $this->read(Path::of($shown));

        return is_string($text) ? $this->migrated($shown, $text, $migrations) : $text;
    }

    /** The baseline as `migrate` would write it; none where there is no baseline; or why it cannot be read. */
    public function baseline(CommandLine $given, Migrations $migrations): Migrated|NotGiven|CannotJudge
    {
        $config = ConfigLocation::in($this->project, $given->config);
        $path = $config instanceof Path ? $this->baselineOf($config) : Floors::standard()->baseline();

        if (! is_file(Root::of($this->project)->at($path)->value())) {
            return NotGiven::value();
        }

        $text = $this->read($path);

        return is_string($text) ? Migrating::baseline($path->value(), $text, $migrations) : $text;
    }

    /** Where a file `migrate` names is on disk. */
    public function onDisk(Migrated $migrated): string
    {
        return Root::of($this->project)->at(Path::of($migrated->file()))->value();
    }

    /**
     * The baseline a config names, read without running anything: a data
     * format's layer, read by the gate's own loader, or the literal path a
     * PHP config gives `Baseline::at()`; the standard one where it names none
     * or does not read.
     */
    private function baselineOf(Path $config): Path
    {
        $file = ConfigFile::at($config, Path::of($this->project));
        $text = $this->read($config);
        $format = Format::fromExtension(pathinfo($config->value(), PATHINFO_EXTENSION));
        $named = $format === Format::Php && is_string($text) ? BaselineCall::in($text) : NotGiven::value();
        $layer = match (true) {
            $format === Format::Json => new JsonConfig()->load($file),
            $format === Format::Yaml && ($this->installed)(Yaml::class) => new YamlConfig()->load($file),
            $format === Format::Neon && ($this->installed)(Neon::class) => new NeonConfig()->load($file),
            default => NotGiven::value(),
        };

        return match (true) {
            is_string($named) => $file->path(Path::of($named)),
            $layer instanceof Layer => $layer->floors()->baseline(),
            default => Floors::standard()->baseline(),
        };
    }

    private function migrated(string $shown, string $text, Migrations $migrations): Migrated|CannotJudge
    {
        $format = Format::fromExtension(pathinfo($shown, PATHINFO_EXTENSION));

        $yaml = $format === Format::Yaml && ($this->installed)(Yaml::class);
        $neon = $format === Format::Neon && ($this->installed)(Neon::class);

        return match (true) {
            $format === Format::Json => Migrating::json($shown, $text, $migrations),
            $format === Format::Php => PhpMigration::of($shown, $text, $migrations),
            $yaml => new YamlConfig()->migrated($shown, $text, $migrations),
            $neon => new NeonConfig()->migrated($shown, $text, $migrations),
            $format instanceof Absent => CannotJudge::because(sprintf(self::NO_FORMAT, $shown)),
            default => CannotJudge::because(
                sprintf(self::NEEDS, $shown, $format->title(), $format->package(), $format->package()),
            ),
        };
    }

    private function read(Path $path): string|CannotJudge
    {
        $file = Root::of($this->project)->at($path)->value();
        $text = is_file($file) ? file_get_contents($file) : false;

        return is_string($text) ? $text : CannotJudge::because(sprintf(self::UNREAD, $path->value()));
    }
}
