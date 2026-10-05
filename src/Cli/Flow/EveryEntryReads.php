<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_any;
use function array_key_exists;

use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Ci\Definitions;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Key\Exceptions;
use NightWorksIO\MutationGate\Core\Test\Role;

/**
 * The files every coverage entry's key reads (ADR-0023, decision 1): under
 * the test directories, what runs before any test and the files that define
 * the runner; outside them, the config, the files that define the runner,
 * every CI definition, and every file that is no PHP source and that no key
 * leaves out.
 */
final readonly class EveryEntryReads
{
    /** @param array<array-key, Role> $roles each file under the test directories, by its path */
    private function __construct(private array $roles, private Exceptions $exceptions, private Path|Absent $config)
    {
    }

    public static function of(Inventory $inventory, Exceptions $exceptions, Path|Absent $config): self
    {
        $roles = [];

        foreach ($inventory->suite->files() as $file) {
            $roles[$file->fingerprint()->path()->value()] = $file->role();
        }

        return new self($roles, $exceptions, $config);
    }

    /** The first file of a change, by either of its paths, that every entry reads; none where none is. */
    public function firstIn(Changes $changes): Path|NotGiven
    {
        foreach ($changes as $change) {
            foreach ([$change->path(), $change->previousPath()] as $path) {
                if ($this->isReadByEvery($path)) {
                    return $path;
                }
            }
        }

        return NotGiven::value();
    }

    /**
     * Whether every entry's key reads a file: under the test directories, one
     * that runs before any test or defines the runner; outside them, as the
     * coverage base reads it.
     */
    public function isReadByEvery(Path $path): bool
    {
        return array_key_exists($path->value(), $this->roles)
            ? $this->roles[$path->value()] === Role::Loaded || $this->exceptions->defines($path)
            : $this->exceptions->defines($path)
                || ($this->config instanceof Path && $path->equals($this->config))
                || array_any([...Definitions::places()], static fn(string $at): bool => $path->within(Path::of($at)))
                || (! $path->isPhp() && ! $this->exceptions->leaveOut($path));
    }
}
