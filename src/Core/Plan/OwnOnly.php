<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Undigested;

/**
 * The files whose units carry only the run's own scope's result, those a
 * `last-run` change reads since its last run but that changed since the ref
 * it falls back to, and the digests of the code on disk that result must
 * still be of (ADR-0005, decision 2). A result stands for such a unit only
 * where it records the unit's source and what decides its mutant set as they
 * are now; where either differs, or the run took no digests, the unit is
 * reached. A last run only says where to look: what a result is of decides
 * whether it is carried.
 */
final readonly class OwnOnly
{
    private function __construct(private Paths $paths, private Digests|Undigested $now)
    {
    }

    public static function none(): self
    {
        return new self(Paths::none(), Undigested::proof());
    }

    /** The units of these files, whose results must be of the code these digests are of. */
    public static function of(Paths $paths, Digests|Undigested $now): self
    {
        return new self($paths, $now);
    }

    /** Whether a unit at this path carries only its own scope's result. */
    public function has(Path $unit): bool
    {
        return $this->paths->has($unit);
    }

    /** Whether a result of the run's own scope is of the code on disk: its source and its mutant set. */
    public function stands(Proof $proof): bool
    {
        $inputs = $proof->inputs();
        $source = $this->now instanceof Digests ? $this->now->sourceOf($proof->unit()) : Undigested::proof();

        return $inputs instanceof Inputs
            && $this->now instanceof Digests
            && $source instanceof Digest
            && $source->value() === $inputs->source()->value()
            && $this->now->mutation()->value() === $inputs->mutation()->value();
    }
}
