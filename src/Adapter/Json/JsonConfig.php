<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Json;

use function file_get_contents;
use function is_file;
use function is_string;
use function json_last_error_msg;
use function json_validate;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Port\ConfigLoader;

use function sprintf;

/** A `mutation-gate.json`, which the published JSON Schema checks in an editor. */
final readonly class JsonConfig implements ConfigLoader
{
    public function load(ConfigFile $file): Layer|Invalid|CannotJudge
    {
        $path = $file->file()->value();
        $text = is_file($path) ? file_get_contents($path) : false;

        return match (true) {
            ! is_string($text) => CannotJudge::because(sprintf('%s could not be read.', $path)),
            ! json_validate($text) => CannotJudge::because(
                sprintf('%s is not JSON: %s.', $path, json_last_error_msg()),
            ),
            default => Definition::layer(Node::config($text), $file),
        };
    }
}
