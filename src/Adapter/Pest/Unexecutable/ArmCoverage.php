<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function count;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Codebase;
use NightWorksIO\MutationGate\Core\Php\MatchArms;
use NightWorksIO\MutationGate\Core\Php\Source;

use function range;

/**
 * Each line of the arms of every match some files hold, covered by the tests
 * that cover it or any later line of those arms. PHP tries a match's arms in
 * order, so a test that runs a later arm ran the condition of every arm
 * before it; coverage may mark an arm's line run only for the tests whose
 * result it is, as pcov marks the condition of the first arm of a
 * `match (true)` on its head.
 *
 * @internal
 */
final readonly class ArmCoverage
{
    public function __construct(private Codebase $codebase)
    {
    }

    /** A map with each line of the arms of every match these files hold covered so. */
    public function onto(Paths $files, CoverageMap $map): CoverageMap
    {
        $covered = $map;

        foreach ($files as $file) {
            $source = $this->codebase->source($file);

            foreach ($source instanceof Source ? MatchArms::in($source->tokens()) : [] as $arms) {
                $covered = $this->armsOnto($covered, $file, $arms);
            }
        }

        return $covered;
    }

    /** A map with each line of one match's arms covered by the tests that cover it or any later line of them. */
    private function armsOnto(CoverageMap $map, Path $file, MatchArms $arms): CoverageMap
    {
        $covered = $map;

        foreach (range($arms->first()->number(), $arms->last()->number()) as $line) {
            $tests = $map->testsCovering($file, Line::of($line), $arms->last());
            $covered = count($tests) === 0 ? $covered : $covered->covered($file, Line::of($line), ...$tests);
        }

        return $covered;
    }
}
