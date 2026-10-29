<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function count;
use function in_array;

use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * A mutant of a line that is not executable tried one part of its test
 * files after another (see TrialParts): a part that kills it, or that its
 * mutant runs out of time in while the part passes on its own, judges it,
 * and no later part runs. A part it survives, or that cannot judge it, as it
 * runs out of time or fails on its own, is followed by the next. Where every
 * part passes, it survived; where none judged it, it is what the first part
 * that could not judge it found, one that failed on its own before one
 * that ran out of time.
 */
final readonly class PartedTrial
{
    /** What a part finds that cannot judge the mutant: its tests run out of time on their own, or it judges nothing. */
    private const array UNJUDGING = [MutantStatus::Skipped, MutantStatus::Unjudged];

    /**
     * @param non-empty-list<TrialRun> $parts
     * @param Outcome|NotGiven         $kept  what the first part that could not judge it found, a failure on its own
     *                                        before a run out of time
     */
    private function __construct(
        private array $parts,
        private int $at,
        private Outcome|NotGiven $kept,
        private Outcome|NotGiven $judged,
    ) {
    }

    /** @param non-empty-list<TrialRun> $parts */
    public static function of(array $parts): self
    {
        return new self($parts, 0, NotGiven::value(), NotGiven::value());
    }

    /** The trial it waits on; none once it is judged. */
    public function next(): TrialRun|NotGiven
    {
        return $this->judged instanceof Outcome ? NotGiven::value() : $this->parts[$this->at];
    }

    /** It, judged as far as what its trial at hand found takes it. */
    public function after(Outcome $found): self
    {
        return match (true) {
            $found->leftAlive() => $this->onward($this->kept, $found),
            in_array($found->status(), self::UNJUDGING, strict: true) => $this->onward($this->keeping($found), $found),
            default => new self($this->parts, $this->at, $this->kept, $found),
        };
    }

    /** What its parts found of it; none until it is judged. */
    public function outcome(): Outcome|NotGiven
    {
        return $this->judged;
    }

    /** What to keep of a part that cannot judge it: what is kept already, unless this failed where that ran out. */
    private function keeping(Outcome $found): Outcome
    {
        $kept = $this->kept;
        $stands = $kept instanceof Outcome
            && ($kept->status() === MutantStatus::Unjudged || $found->status() === MutantStatus::Skipped);

        return $stands ? $kept : $found;
    }

    /** It, on to its next part, keeping this; after its last, what is kept, or what that part found. */
    private function onward(Outcome|NotGiven $kept, Outcome $found): self
    {
        return $this->at + 1 < count($this->parts)
            ? new self($this->parts, $this->at + 1, $kept, NotGiven::value())
            : new self($this->parts, $this->at, $kept, $kept instanceof Outcome ? $kept : $found);
    }
}
