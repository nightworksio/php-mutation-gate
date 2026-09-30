<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Neon;

use function file_get_contents;
use function is_file;
use function is_string;
use function json_decode;

use Nette\Neon\Exception;
use Nette\Neon\Neon;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Parsed;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Port\ConfigLoader;

use function sprintf;

/**
 * A `mutation-gate.neon`, read with `nette/neon`, which is optional. NEON
 * reads a date as a date by itself, and it is written `YYYY-MM-DD` again.
 */
final readonly class NeonConfig implements ConfigLoader
{
    private const string INDENT = '    ';

    public function load(Path $file): Document|CannotJudge
    {
        $text = is_file($file->value()) ? file_get_contents($file->value()) : false;

        if (! is_string($text)) {
            return CannotJudge::because(sprintf('%s could not be read.', $file->value()));
        }

        try {
            $tree = Neon::decode($text);
        } catch (Exception $exception) {
            return CannotJudge::because(sprintf('%s is not NEON: %s', $file->value(), $exception->getMessage()));
        }

        return Parsed::document($tree, $file->value());
    }

    /** A config written as NEON, as `init` and `config:show` write it. */
    public function render(Document $document): string
    {
        $tree = json_decode($document->json(), associative: true);

        return Neon::encode($tree, blockMode: true, indentation: self::INDENT);
    }
}
