<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Php;

use Error;

use function get_debug_type;
use function is_file;

use JsonException;
use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Port\ConfigLoader;

use function sprintf;

/**
 * A `mutation-gate.php`, the canonical format (ADR-0002). The file returns
 * `Gate::configure()` with its settings, and anything else is refused: a
 * config is data, and code that has to run belongs in an extension.
 */
final readonly class PhpConfig implements ConfigLoader
{
    public function load(ConfigFile $file): Layer|Invalid|CannotJudge
    {
        $path = $file->file()->value();

        if (! is_file($path)) {
            return CannotJudge::because(sprintf('%s could not be read.', $path));
        }

        try {
            $returned = (static fn(string $path): mixed => require $path)($path);
        } catch (Error|JsonException $error) {
            return CannotJudge::because(sprintf('%s could not be read: %s', $path, $error->getMessage()));
        }

        return $returned instanceof Gate
            ? $returned->layer($file)
            : CannotJudge::because(sprintf(
                '%s returns %s. It must return Gate::configure() with its settings.',
                $path,
                get_debug_type($returned),
            ));
    }
}
