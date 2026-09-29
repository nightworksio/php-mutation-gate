<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Php;

use Error;

use function get_debug_type;
use function is_file;

use JsonException;
use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Port\ConfigLoader;

use function sprintf;

/**
 * A `mutation-gate.php`, the canonical format (ADR-0002). The file returns
 * `Gate::configure()` with its settings, and anything else is refused: a
 * config is data, and code that has to run belongs in an extension.
 */
final readonly class PhpConfig implements ConfigLoader
{
    public function load(Path $file): Document|CannotJudge
    {
        if (! is_file($file->value())) {
            return CannotJudge::because(sprintf('%s could not be read.', $file->value()));
        }

        try {
            $returned = (static fn(string $path): mixed => require $path)($file->value());
        } catch (Error|JsonException $error) {
            return CannotJudge::because(sprintf('%s could not be read: %s', $file->value(), $error->getMessage()));
        }

        return $returned instanceof Gate
            ? $returned->document()
            : CannotJudge::because(sprintf(
                '%s returns %s. It must return Gate::configure() with its settings.',
                $file->value(),
                get_debug_type($returned),
            ));
    }
}
