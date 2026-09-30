<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Fields;
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

    /** The floors a config was read into. */
    public static function read(Fields $read): self
    {
        return new self(
            $read->has('trees') ? Listed::of($read->objects('trees', DeclaredTree::class)) : Absent::setting(),
            $read->fields('newCode')->object('floor', Floor::class),
            $read->object('uncovered', UncoveredMutants::class),
            $read->fields('baseline')->object('path', Path::class),
            $read->fields('baseline')->object('improvement', Improvement::class),
        );
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
