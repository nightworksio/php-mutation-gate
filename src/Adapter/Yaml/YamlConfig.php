<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Yaml;

use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Parsed;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
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

    public function load(ConfigFile $file): Layer|Invalid|CannotJudge
    {
        $path = $file->file()->value();
        $text = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($text)) {
            return CannotJudge::because(sprintf('%s could not be read.', $path));
        }

        try {
            $tree = Yaml::parse($text, Yaml::PARSE_DATETIME | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            return CannotJudge::because(sprintf('%s is not YAML: %s', $path, $exception->getMessage()));
        }

        $json = Parsed::json($tree, $path);

        return $json instanceof Json ? Definition::layer(Node::config($json->line()), $file) : $json;
    }

    /** A config written as YAML, as `init` and `config:show` write it. */
    public function render(Json $config): string
    {
        return Yaml::dump($config->plain(), self::NESTED, self::INDENT, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }
}
