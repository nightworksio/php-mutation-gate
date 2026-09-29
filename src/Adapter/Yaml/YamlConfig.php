<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Yaml;

use function file_get_contents;
use function is_file;
use function is_string;
use function json_decode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Parsed;
use NightWorksIO\MutationGate\Core\File\Path;
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

    public function load(Path $file): Document|CannotJudge
    {
        $text = is_file($file->value()) ? file_get_contents($file->value()) : false;

        if (! is_string($text)) {
            return CannotJudge::because(sprintf('%s could not be read.', $file->value()));
        }

        try {
            $tree = Yaml::parse($text, Yaml::PARSE_DATETIME | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            return CannotJudge::because(sprintf('%s is not YAML: %s', $file->value(), $exception->getMessage()));
        }

        return Parsed::document($tree, $file->value());
    }

    /** A config written as YAML, as `init` and `config:show` write it. */
    public function render(Document $document): string
    {
        return Yaml::dump(
            json_decode($document->json(), associative: true),
            self::NESTED,
            self::INDENT,
            Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE,
        );
    }
}
