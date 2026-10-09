<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Php;

use Error;

use function file_get_contents;
use function get_debug_type;
use function is_file;
use function is_string;

use JsonException;
use LogicException;
use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Migration\Migrations;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Port\ConfigLoader;
use RuntimeException;

use function sprintf;

/**
 * A `mutation-gate.php`, the canonical format (ADR-0002). The file returns
 * `Gate::configure()` with its settings, and anything else is refused: a
 * config is data, and code that has to run belongs in an extension.
 */
final readonly class PhpConfig implements ConfigLoader
{
    /** @param Migrations|NotGiven $migrations what the releases retired, the gate's own where none is given */
    public function __construct(private Migrations|NotGiven $migrations = new NotGiven())
    {
    }

    /**
     * The files the config requires or includes by a literal path, and those
     * they include in turn, read from its code without running it; or why it
     * reads one that cannot be named.
     */
    public function reads(ConfigFile $file): ConfigReads
    {
        return PhpReads::of($file);
    }

    public function load(ConfigFile $file): Layer|Invalid|CannotJudge
    {
        $path = $file->file()->value();

        if (! is_file($path)) {
            return CannotJudge::because(sprintf('%s could not be read.', $path));
        }

        try {
            $returned = (static fn(string $path): mixed => require $path)($path);
        } catch (Error|JsonException|LogicException|RuntimeException $error) {
            return CannotJudge::because($this->unread($path, $error->getMessage()));
        }

        return $returned instanceof Gate
            ? $returned->layer($file)
            : CannotJudge::because(sprintf(
                '%s returns %s. It must return Gate::configure() with its settings.',
                $path,
                get_debug_type($returned),
            ));
    }

    /**
     * Why a config could not be read: the first call it still makes that a
     * release retired, with the change and `migrate` (ADR-0026, decision 1),
     * or else what PHP said.
     */
    private function unread(string $path, string $said): string
    {
        $code = file_get_contents($path);
        $migrations = $this->migrations instanceof Migrations ? $this->migrations : Migrations::config();
        $pending = is_string($code) ? PhpMigration::pending($path, $code, $migrations) : NotGiven::value();

        return is_string($pending) ? $pending : sprintf('%s could not be read: %s', $path, $said);
    }
}
