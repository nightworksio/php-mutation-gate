<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Project\PhpUnitIni;
use NightWorksIO\MutationGate\Core\Doctor\PhpUnitMemory;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;

/**
 * The `memory_limit` the project sets itself, in the first of the PHPUnit
 * configs the runner runs with that the project has (ADR-0004, decision 9):
 * those in the root for Pest, and in `phpUnit.configDir` for Infection.
 * PHPUnit sets it after PHP has read `runner.memory`'s cap, so where it is
 * higher, or none, it is the limit each mutant's process runs under.
 */
final readonly class ProjectMemoryLimit
{
    public static function in(Directory $project, Paths $configs): PhpUnitMemory|NotGiven
    {
        foreach ($configs as $candidate) {
            $read = $project->read($candidate);

            if (! $read instanceof Missing) {
                $limit = PhpUnitIni::memoryIn($read instanceof Contents ? $read->text() : '');

                return $limit instanceof MemoryCap ? PhpUnitMemory::of($candidate, $limit) : $limit;
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
}
