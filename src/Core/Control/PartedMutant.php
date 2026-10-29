<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function count;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\UnmutatedFailure;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * A heavy mutant judged one part of its tests after another (ADR-0008):
 * each part run with the mutant served, stopped at its limit or where no
 * test finishes for its silence limit. A part a test fails in kills it, and
 * no later part runs. A part that runs out has its control, the same tests
 * on the file unmutated under the same limit: where they pass, the mutant
 * timed out where its tests do not; where they run out too, or fail, that
 * part cannot judge it, and the next runs. Where every part passes, it
 * survived; where none killed it and some part could not judge it, it is
 * too slow to judge, or unjudged where a part fails unmutated.
 */
final readonly class PartedMutant
{
    /** Why a heavy mutant is too slow to judge where some part's control ran out too. */
    public const string RAN_OUT
        = 'Part %d of %d of its tests runs out of its limit unmutated too, so nothing tells a hang from slow tests.';

    /** Why a heavy mutant is unjudged where some part's tests fail unmutated. */
    public const string FAILS_UNMUTATED = 'Part %d of %d of its tests fails with the file unmutated too.';

    /** Why a heavy mutant is unjudged where a part ran out of the memory cap. */
    public const string OUT_OF_MEMORY = 'Part %d of %d of its tests ran out of the memory cap.';

    /** Why a heavy mutant is unjudged where a part could not run. */
    public const string UNRUN = 'Part %d of %d of its tests could not run: %s';

    /**
     * @param list<array{Control, Control}> $parts       each part, served the mutant and unmutated, in order
     * @param bool                          $controlling whether the part at hand runs next unmutated, as the
     *                                                   control of a part that ran out
     */
    private function __construct(
        private Mutant $mutant,
        private array $parts,
        private int $at,
        private bool $controlling,
        private Reason|UnmutatedFailure|NotGiven $unjudged,
        private Mutant|NotGiven $judged,
    ) {
    }

    /** @param non-empty-list<array{Control, Control}> $parts each part, served the mutant and unmutated, in order */
    public static function of(Mutant $mutant, array $parts): self
    {
        return new self($mutant, $parts, 0, controlling: false, unjudged: NotGiven::value(), judged: NotGiven::value());
    }

    public function mutant(): Mutant
    {
        return $this->mutant;
    }

    /** The run it waits on: a part served the mutant, or a part's control; none once it is judged. */
    public function next(): Control|NotGiven
    {
        return $this->judged instanceof Mutant
            ? NotGiven::value()
            : $this->parts[$this->at][$this->controlling ? 1 : 0];
    }

    /** It, judged as far as what its run at hand found takes it. */
    public function after(ControlRun $run): self
    {
        return $this->controlling ? $this->controlled($run) : $this->ran($run);
    }

    /** It, unjudged, the time budget having run out before its run at hand. */
    public function outOfTime(): self
    {
        return $this->ending($this->mutant->unjudged(OutOfTime::BeforeControlling));
    }

    /** How it was judged; as it stood, where no part has yet. */
    public function judged(): Mutant
    {
        return $this->judged instanceof Mutant ? $this->judged : $this->mutant;
    }

    /** It, after its part served the mutant ran. */
    private function ran(ControlRun $run): self
    {
        $limit = $this->parts[$this->at][0]->limit();

        return match ($run->end()) {
            ControlEnd::Failed => $this->ending(
                $this->as(MutantStatus::Killed)->killedBy($run->failing())->withLimit($limit),
            ),
            ControlEnd::Passed => $this->onward($this->unjudged),
            ControlEnd::RanOut => new self(
                $this->mutant,
                $this->parts,
                $this->at,
                controlling: true,
                unjudged: $this->unjudged,
                judged: $this->judged,
            ),
            ControlEnd::OutOfMemory => $this->ending($this->mutant->unjudged($this->said(self::OUT_OF_MEMORY))),
            ControlEnd::Unrun => $this->ending($this->mutant->unjudged($this->unrun($run))),
        };
    }

    /** It, after the control of a part that ran out ran. */
    private function controlled(ControlRun $run): self
    {
        $control = $this->parts[$this->at][1];
        $took = $run->took();

        return match ($run->end()) {
            ControlEnd::Passed => $this->ending(
                $this->as(MutantStatus::TimedOut)
                    ->withLimit($control->limit())
                    ->withUnmutatedNeed($took instanceof Seconds ? $took : $control->limit()),
            ),
            ControlEnd::RanOut => $this->onward($this->said(self::RAN_OUT)),
            ControlEnd::Failed => $this->onward(
                $run->unmutatedFailure($control->tests(), $this->said(self::FAILS_UNMUTATED)),
            ),
            ControlEnd::OutOfMemory => $this->onward($this->said(self::OUT_OF_MEMORY)),
            ControlEnd::Unrun => $this->ending($this->mutant->unjudged($this->unrun($run))),
        };
    }

    /**
     * It, on to its next part, keeping why a part could not judge it; or
     * judged, once its last part has run.
     */
    private function onward(Reason|UnmutatedFailure|NotGiven $unjudged): self
    {
        $kept = $this->unjudged instanceof NotGiven || $unjudged instanceof UnmutatedFailure
            ? $unjudged
            : $this->unjudged;

        return $this->at + 1 < count($this->parts)
            ? new self(
                $this->mutant,
                $this->parts,
                $this->at + 1,
                controlling: false,
                unjudged: $kept,
                judged: $this->judged,
            )
            : $this->ending(match (true) {
                $kept instanceof NotGiven => $this->as(MutantStatus::Survived),
                $kept instanceof UnmutatedFailure => $this->mutant->unjudged($kept),
                default => $this->mutant->because($kept),
            });
    }

    private function ending(Mutant $judged): self
    {
        return new self(
            $this->mutant,
            $this->parts,
            $this->at,
            controlling: $this->controlling,
            unjudged: $this->unjudged,
            judged: $judged,
        );
    }

    /** The mutant as its parts judged it, with this status, and nothing its first run told of it. */
    private function as(MutantStatus $status): Mutant
    {
        $mutant = $this->mutant;

        return Mutant::of(
            $mutant->id(),
            $mutant->nativeId(),
            $mutant->location(),
            $mutant->mutation(),
            $status,
            $mutant->duration(),
        );
    }

    /** One of the reasons above, of the part at hand. */
    private function said(string $reason): Reason
    {
        return Reason::that(sprintf($reason, $this->at + 1, count($this->parts)));
    }

    private function unrun(ControlRun $run): Reason
    {
        $why = $run->why();

        return Reason::that(sprintf(
            self::UNRUN,
            $this->at + 1,
            count($this->parts),
            $why instanceof NotGiven ? ControlRuns::NOT_RUN : $why,
        ));
    }
}
