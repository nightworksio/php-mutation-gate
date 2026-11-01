<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\PhpUnitMemory;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitIni;

/**
 * The `memory_limit` the project sets itself, in the first of the PHPUnit
 * configs the runner runs with that the project has (ADR-0004):
 * those in the root for Pest and PHPUnit, and in `phpUnit.configDir` for
 * Infection.
 * PHPUnit sets it after PHP has read `runner.memory`'s cap, so where it is
 * higher, or none, it is the limit each mutant's process runs under.
 *
 * @internal
 */
final readonly class ProjectMemoryLimit
{
    public static function in(Directory $project, Paths $configs): PhpUnitMemory|NotGiven
    {
        foreach ($configs as $candidate) {
            $read = $project->read($candidate);

            if (! $read instanceof Missing) {
                return self::setIn($candidate, $read);
            }
        }

        return NotGiven::value();
    }

    /** The cap each mutant's process runs under: the project's own limit where it lifts the cap, else the cap. */
    public static function inForce(Directory $project, Paths $configs, MemoryCap $cap): MemoryCap
    {
        $own = self::in($project, $configs);

        return $own instanceof PhpUnitMemory && $cap->isExceededBy($own->limit()) ? $own->limit() : $cap;
    }

    /** The limit a config the project has sets; none where it sets none, or cannot be read. */
    private static function setIn(Path $config, Contents|CannotJudge $read): PhpUnitMemory|NotGiven
    {
        $limit = $read instanceof Contents ? PhpUnitIni::memoryIn($read->text()) : NotGiven::value();

        return $limit instanceof MemoryCap ? PhpUnitMemory::of($config, $limit) : $limit;
    }
}
