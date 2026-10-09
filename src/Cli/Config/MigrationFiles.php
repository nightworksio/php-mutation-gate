<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use Closure;

use function file_get_contents;
use function is_file;
use function is_string;

use Nette\Neon\Neon;
use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Php\PhpMigration;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Floors;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Migration\Migrated;
use NightWorksIO\MutationGate\Core\Migration\Migrating;
use NightWorksIO\MutationGate\Core\Migration\Migrations;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Extension\Extensions;

use function pathinfo;
use function sprintf;

use Symfony\Component\Yaml\Yaml;

/**
 * The files `migrate` moves forward (ADR-0026, decisions 4 and 5): the
 * config, in the format its extension names, and the baseline the config
 * names, or the one at its standard path while the config does not read.
 * It reads the config's layer alone, so it needs no runner installed.
 */
final readonly class MigrationFiles
{
    private const string UNREAD = '%s could not be read.';

    private const string NO_FORMAT
        = '%s is no config migrate can read. Name a mutation-gate.php, .json, .yaml, .yml or .neon file.';

    private const string NEEDS = '%s is %s, which needs %s to be migrated. Install it: composer require --dev %s';

    /** @param Closure(class-string): bool $installed whether a library's class can be loaded */
    public function __construct(private string $project, private Extensions $extensions, private Closure $installed)
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
        $layer = $config instanceof Path ? $this->layerOf($config) : NotGiven::value();
        $path = $layer instanceof Layer ? $layer->floors()->baseline() : Floors::standard()->baseline();

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

    /** The layer the config writes, read by its format's loader; or why it does not read, as a pending change. */
    private function layerOf(Path $config): Layer|Invalid|CannotJudge
    {
        $file = ConfigFile::at($config, Path::of($this->project));
        $loader = Formats::loader($this->extensions, $file);

        return $loader instanceof CannotJudge ? $loader : $loader->load($file);
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
