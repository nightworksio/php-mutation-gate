<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Json;

use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Port\ConfigLoader;

use function sprintf;

/** A `mutation-gate.json`, which the published JSON Schema checks in an editor. */
final readonly class JsonConfig implements ConfigLoader
{
    /** JSON names no other file. */
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

        $json = Json::parse($text);

        return $json instanceof Json
            ? $file->read($json)
            : CannotJudge::because(sprintf('%s is not JSON: %s', $path, $json->why()));
    }
}
