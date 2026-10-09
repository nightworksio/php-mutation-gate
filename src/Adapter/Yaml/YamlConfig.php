<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Yaml;

use function file_get_contents;
use function is_file;
use function is_string;
use function json_decode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Parsed;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Migration\Migrated;
use NightWorksIO\MutationGate\Core\Migration\Migrating;
use NightWorksIO\MutationGate\Core\Migration\Migrations;
use NightWorksIO\MutationGate\Port\ConfigLoader;

use function sprintf;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A `mutation-gate.yaml` or `.yml`, read with `symfony/yaml`, which is
 * optional. A date needs no quotes: it is read as a date and written
 * `YYYY-MM-DD` again.
 */
final readonly class YamlConfig implements ConfigLoader
{
    private const int NESTED = 10;

    private const int INDENT = 2;

    /** The gate reads YAML as data alone, which names no other file. */
    public function reads(ConfigFile $file): ConfigReads
    {
        return ConfigReads::none();
    }

    public function load(ConfigFile $file): Layer|Invalid|CannotJudge
    {
        $path = $file->file()->value();
        $text = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($text)) {
            return CannotJudge::because(sprintf('%s could not be read.', $path));
        }

        $json = $this->form($text, $path);

        return $json instanceof Json ? $file->read($json) : $json;
    }

    /**
     * A YAML config as `migrate` would write it, written again from its
     * migrated form, which keeps none of its comments (ADR-0026, decision 3);
     * or why it cannot be read.
     */
    public function migrated(string $shown, string $text, Migrations $migrations): Migrated|CannotJudge
    {
        $json = $this->form($text, $shown);

        return $json instanceof Json
            ? Migrating::rewritten($shown, $text, $json, $migrations, $this->render(...))
            : $json;
    }

    /** A config written as YAML, as `init` and `config:show` write it. */
    public function render(Json $config): string
    {
        return Yaml::dump(
            json_decode($config->line(), associative: true),
            self::NESTED,
            self::INDENT,
            Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE,
        );
    }

    /** The JSON form a YAML text reads into, or why it holds no config. */
    private function form(string $text, string $path): Json|CannotJudge
    {
        try {
            $tree = Yaml::parse($text, Yaml::PARSE_DATETIME | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            return CannotJudge::because(sprintf('%s is not YAML: %s', $path, $exception->getMessage()));
        }

        return Parsed::json($tree, $path);
    }
}
