<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;

/**
 * What decides a score and the floor it is held to (ADR-0003): `trees`,
 * `newCode`, `uncovered` and `baseline`.
 */
final readonly class Floors
{
    /** @param Listed<DeclaredTree>|Absent $trees */
    public function __construct(
        private Listed|Absent $trees,
        private Floor $newCode,
        private UncoveredMutants $uncovered,
        private Path $baseline,
        private Improvement $improvement,
    ) {
    }

    /** @return Listed<DeclaredTree>|Absent the trees the config declares, or none, when the tree source finds them */
    public function trees(): Listed|Absent
    {
        return $this->trees;
    }

    /** `newCode.floor` */
    public function newCode(): Floor
    {
        return $this->newCode;
    }

    public function uncovered(): UncoveredMutants
    {
        return $this->uncovered;
    }

    /** `baseline.path` */
    public function baseline(): Path
    {
        return $this->baseline;
    }

    /** `baseline.improvement` */
    public function improvement(): Improvement
    {
        return $this->improvement;
    }
}
