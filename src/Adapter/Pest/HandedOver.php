<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_values;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;

/**
 * The map another job handed over, as a mutation run that opened on it covers
 * each line: Pest's opening map is that map again, so the gate reads the one
 * it already holds rather than a copy it would have to read back.
 */
final readonly class HandedOver implements Covering
{
    public function __construct(private CoverageMap $map, private Project $project)
    {
    }

    public function testsCovering(string $file, int $first, int $last): array
    {
        $path = $this->project->relative($file);
        $tests = [];

        foreach ($this->map->linesCovered($path) as $line) {
            $covered = $line->number() >= $first && $line->number() <= $last;

            foreach ($covered ? $this->map->testsCovering($path, $line) : [] as $test) {
                $tests[$test->value()] = $test->value();
            }
        }

        return array_values($tests);
    }

    public function map(Project $project): CoverageMap
    {
        return $this->map;
    }
}
