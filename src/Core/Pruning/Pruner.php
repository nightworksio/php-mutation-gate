<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Pruning;

use function array_any;
use function count;

use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Pruning;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * Which mutators a run with a base leaves out of which of the units it runs
 * (ADR-0025): the mutators of its runner whose newest
 * judged mutants let nothing through, less those never pruned, in each file
 * whose newest full result is of its code, mutant set and tests as they are
 * now (ADR-0108), was made since the audit's start and let nothing through
 * (ADR-0106). A run with no base, a held unit, a unit whose result is older,
 * of other code or tests or holds a survivor, an uncovered or an unjudged
 * mutant, and every unit where pruning is off, run every mutator.
 *
 * @internal
 */
final readonly class Pruner
{
    private function __construct(
        private Pruning $settings,
        private Name $runner,
        private MutatorNames $never,
        private Instant $since,
    ) {
    }

    /**
     * A pruner by these settings, for this runner, never pruning these
     * mutators, carrying no result made before this instant.
     */
    public static function of(Pruning $settings, Name $runner, MutatorNames $never, Instant $since): self
    {
        return new self($settings, $runner, $never, $since);
    }

    /**
     * What a run with a base leaves out of the units it runs, by what the
     * ledgers learned, their newest results, and the digests of the code on
     * disk.
     */
    public function pruned(Survival $survival, Units $toRun, NewestProofs $newest, Digests|Undigested $now): Pruned
    {
        $mutators = $survival->pruned($this->runner, $this->settings->window(), $this->never);

        if (! $this->settings->enabled() || count($mutators) === 0 || ! $now instanceof Digests) {
            return Pruned::none();
        }

        $files = [];

        foreach ($toRun as $unit) {
            $files = $this->carries($unit, $newest, $now) ? [...$files, $unit->path()] : $files;
        }

        return Pruned::of($mutators, Paths::of(...$files));
    }

    /**
     * Whether a full result is of a unit's code, mutant set and tests as these
     * digests say they are now: every test file it records, each that covers
     * the unit or killed one of its mutants, reads as it did (ADR-0108).
     */
    public static function stands(Proof $proof, Path $unit, Digests $now): bool
    {
        $inputs = $proof->inputs();
        $source = $now->sourceOf($unit);

        return $inputs instanceof Inputs
            && $source instanceof Digest
            && $source->value() === $inputs->source()->value()
            && $now->mutation()->value() === $inputs->mutation()->value()
            && self::testsRead($inputs, $now);
    }

    /** Whether every test file a result records reads now as it did then. */
    private static function testsRead(Inputs $inputs, Digests $now): bool
    {
        foreach ($inputs->tests() as $file => $then) {
            $current = $now->testOf($file);

            if (! $current instanceof Digest || $current->value() !== $then->value()) {
                return false;
            }
        }

        return true;
    }

    /** Whether a result holds a mutant that let the change through, which a run judges again rather than carry. */
    private function letThrough(Proof $proof): bool
    {
        return array_any([...$proof->reported()], static fn(Mutant $mutant): bool => $mutant->status()->letsThrough());
    }

    /**
     * Whether a unit's newest full result stands for its code as it is now, is recent enough to carry, and let
     * nothing through.
     */
    private function carries(Unit $unit, NewestProofs $newest, Digests $now): bool
    {
        $proof = $newest->of($unit->path());

        return ! $unit->isHeld()
            && $proof instanceof Proof
            && self::stands($proof, $unit->path(), $now)
            && ! $this->since->isAfter($proof->run()->at())
            && ! $this->letThrough($proof);
    }
}
