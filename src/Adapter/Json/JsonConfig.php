<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Json;

use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Port\ConfigLoader;

use function sprintf;

/** A `mutation-gate.json`, which the published JSON Schema checks in an editor. */
final readonly class JsonConfig implements ConfigLoader
{
    public function load(Path $file): Document|CannotJudge
    {
        $text = is_file($file->value()) ? file_get_contents($file->value()) : false;

        if (! is_string($text)) {
            return CannotJudge::because(sprintf('%s could not be read.', $file->value()));
        }

        $document = Document::ofJson($text);

        return $document instanceof Document
            ? $document
            : CannotJudge::because(sprintf('%s: %s', $file->value(), $document->why()));
    }
}
