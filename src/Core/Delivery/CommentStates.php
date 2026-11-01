<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Delivery;

use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\PlannedWork;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Report\Markdown;
use NightWorksIO\MutationGate\Core\Report\PlannedMarkdown;
use NightWorksIO\MutationGate\Core\Report\RecheckedMarkdown;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;

/**
 * The states a sticky comment is written in, whichever forge keeps it: planned, re-checked over its planned state
 * alone, and judged (ADR-0009, ADR-0019, ADR-0020). Each is written through the thread now, or left in a
 * delivery where the run is a pull request's or a merge request's.
 */
final readonly class CommentStates implements Commenting
{
    /** Why a run comments on no pull request. */
    public const string NOT_A_PULL_REQUEST = 'This run is not for a pull request, so there is no comment to write.';

    /** Why a re-check leaves the comment as it is. */
    public const string NOT_PLANNED
        = 'The comment is no longer in its planned state, so the re-checked survivors leave it as it is.';

    private function __construct(private CommentThread $thread, private string $run, private bool $commentsHere)
    {
    }

    /**
     * The states of the comment in this thread, linking the run at this URL, left in a delivery only where the run
     * comments here: it is a pull request's or a merge request's.
     */
    public static function in(CommentThread $thread, string $run, bool $commentsHere): self
    {
        return new self($thread, $run, $commentsHere);
    }

    /** The comment holding the verdict, written now. */
    public function judged(Verdict $verdict): Written|NotWritten
    {
        return $this->write(Markdown::comment($verdict));
    }

    /** A delivery, with the comment holding the verdict, where the run comments; unchanged on any other run. */
    public function judgedLater(Verdict $verdict, Delivery $delivery): Delivery
    {
        return $this->commentsHere ? $delivery->withComment(Markdown::comment($verdict)) : $delivery;
    }

    public function planned(PlannedWork $work): Written|NotWritten
    {
        return $this->write(PlannedMarkdown::comment($work, $this->run));
    }

    public function plannedLater(PlannedWork $work, Delivery $delivery): Delivery
    {
        return $this->commentsHere ? $delivery->withComment(PlannedMarkdown::comment($work, $this->run)) : $delivery;
    }

    public function rechecked(Rechecked $rechecked): Written|NotWritten
    {
        return $this->writeOverPlanned(RecheckedMarkdown::comment($rechecked, $this->run));
    }

    public function recheckedLater(Rechecked $rechecked, Delivery $delivery): Delivery
    {
        return $this->commentsHere
            ? $delivery->withCommentOverPlanned(RecheckedMarkdown::comment($rechecked, $this->run))
            : $delivery;
    }

    public function write(string $markdown): Written|NotWritten
    {
        return $this->thread->written($markdown, overPlanned: false);
    }

    public function writeOverPlanned(string $markdown): Written|NotWritten
    {
        return $this->thread->written($markdown, overPlanned: true);
    }
}
