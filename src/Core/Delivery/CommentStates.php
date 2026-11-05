<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Delivery;

use NightWorksIO\MutationGate\Core\Comment\Occasion;
use NightWorksIO\MutationGate\Core\Comment\Passing;
use NightWorksIO\MutationGate\Core\Comment\State;
use NightWorksIO\MutationGate\Core\Comment\Toggle;
use NightWorksIO\MutationGate\Core\Config\CommentSettings;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\PlannedWork;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Report\Markdown;
use NightWorksIO\MutationGate\Core\Report\PlannedMarkdown;
use NightWorksIO\MutationGate\Core\Report\RecheckedMarkdown;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * The states a comment is written in, whichever forge keeps it: planned, re-checked over its planned state alone,
 * and judged (ADR-0009, ADR-0019, ADR-0020), each as `comment` shapes it (ADR-0102): only the states and kinds of
 * run it names, a draft's, a sampled and a cut-short run only where it lets them, and a passing run as
 * `comment.passing` says. Each is written through the thread now, or left in a delivery where the run is a pull
 * request's or a merge request's.
 *
 * @internal
 */
final readonly class CommentStates implements Commenting
{
    /** Why a run comments on no pull request. */
    public const string NOT_A_PULL_REQUEST = 'This run is not for a pull request, so there is no comment to write.';

    /** Why a re-check leaves the comment as it is. */
    public const string NOT_PLANNED
        = 'The comment is no longer in its planned state, so the re-checked survivors leave it as it is.';

    /** Why a passing run under `comment.passing: update` writes no comment. */
    public const string NOT_THERE
        = 'The run passed and the gate has not commented before, so comment.passing: update writes nothing.';

    private const string LEFT_OUT = 'comment.states leaves out the %s state, so it is not written.';

    private const string NOT_ON = 'comment.on leaves out pull requests, so no comment is written.';

    private const string KEPT = 'The run passed and comment.passing is never, so the comment is left as it is.';

    private const string NOT_LET = 'comment.%s is false, so this run writes no comment.';

    private function __construct(
        private CommentThread $thread,
        private string $run,
        private bool $commentsHere,
        private CommentSettings $settings,
        private bool $draft,
    ) {
    }

    /**
     * The states of the comment in this thread, linking the run at this URL, left in a delivery only where the run
     * comments here: it is a pull request's or a merge request's, a draft or not.
     */
    public static function in(
        CommentThread $thread,
        string $run,
        bool $commentsHere,
        CommentSettings $settings,
        bool $draft = false,
    ): self {
        return new self($thread, $run, $commentsHere, $settings, $draft);
    }

    /** The comment holding the verdict, written now; or why it is not. */
    public function judged(Verdict $verdict): Written|NotWritten
    {
        $why = $this->why(State::Verdict, $verdict);

        return $why instanceof NotWritten ? $why : $this->writeAs($this->verdictOf($verdict), $this->write($verdict));
    }

    /** A delivery, with the comment holding the verdict, where the run comments; unchanged on any other run. */
    public function judgedLater(Verdict $verdict, Delivery $delivery): Delivery
    {
        return $this->commentsHere && ! $this->why(State::Verdict, $verdict) instanceof NotWritten
            ? $delivery->withComment($this->verdictOf($verdict), $this->write($verdict))
            : $delivery;
    }

    public function planned(PlannedWork $work): Written|NotWritten
    {
        $why = $this->why(State::Planned, NotGiven::value());

        return $why instanceof NotWritten
            ? $why
            : $this->writeAs($this->plannedOf($work), $this->write(NotGiven::value()));
    }

    public function plannedLater(PlannedWork $work, Delivery $delivery): Delivery
    {
        return $this->commentsHere && ! $this->why(State::Planned, NotGiven::value()) instanceof NotWritten
            ? $delivery->withComment($this->plannedOf($work), $this->write(NotGiven::value()))
            : $delivery;
    }

    public function rechecked(Rechecked $rechecked): Written|NotWritten
    {
        $why = $this->why(State::Rechecked, NotGiven::value());

        return $why instanceof NotWritten
            ? $why
            : $this->writeAs($this->recheckedOf($rechecked), $this->write(NotGiven::value())->overPlanned());
    }

    public function recheckedLater(Rechecked $rechecked, Delivery $delivery): Delivery
    {
        return $this->commentsHere && ! $this->why(State::Rechecked, NotGiven::value()) instanceof NotWritten
            ? $delivery->withComment($this->recheckedOf($rechecked), $this->write(NotGiven::value())->overPlanned())
            : $delivery;
    }

    /** Whether the comment is written in this state at all, as `comment.states` and `comment.on` say. */
    public function writesIn(State $state): bool
    {
        return ! $this->why($state, NotGiven::value()) instanceof NotWritten;
    }

    public function writeAs(string $markdown, CommentWrite $write): Written|NotWritten
    {
        return $this->thread->written($markdown, $write);
    }

    private function verdictOf(Verdict $verdict): string
    {
        return $this->marked(Markdown::comment($verdict, $this->settings->shows()));
    }

    private function plannedOf(PlannedWork $work): string
    {
        return $this->marked(PlannedMarkdown::comment($work, $this->run));
    }

    private function recheckedOf(Rechecked $rechecked): string
    {
        return $this->marked(RecheckedMarkdown::comment($rechecked, $this->run, $this->settings->shows()));
    }

    private function marked(string $markdown): string
    {
        return $this->write(NotGiven::value())->marked($markdown);
    }

    /** How the comment is written: as `comment.mode` says, and, of a passing verdict, as `comment.passing` does. */
    private function write(Verdict|NotGiven $verdict): CommentWrite
    {
        $write = CommentWrite::of($this->settings->writes()->mode(), $this->run);
        $updates = $this->settings->writes()->passing() === Passing::Update;

        return $verdict instanceof Verdict && $verdict->judgement() === Judgement::Passed && $updates
            ? $write->existingOnly()
            : $write;
    }

    /** Why the comment is not written in this state, of this verdict where there is one; none where it is. */
    private function why(State $state, Verdict|NotGiven $verdict): NotWritten|NotGiven
    {
        $writes = $this->settings->writes();
        $passed = $verdict instanceof Verdict && $verdict->judgement() === Judgement::Passed;

        return match (true) {
            ! $writes->commentsOn(Occasion::PullRequest, [Occasion::PullRequest]) => NotWritten::because(self::NOT_ON),
            ! $writes->writes($state) => NotWritten::because(sprintf(self::LEFT_OUT, $state->value)),
            $this->draft && ! $writes->allows(Toggle::Drafts) => $this->notLet(Toggle::Drafts),
            $verdict instanceof Verdict => $this->whyNotOf($verdict, $passed),
            default => NotGiven::value(),
        };
    }

    private function whyNotOf(Verdict $verdict, bool $passed): NotWritten|NotGiven
    {
        $writes = $this->settings->writes();
        $sampled = $verdict->trees()->sample()->isDrawn();

        return match (true) {
            $sampled && ! $writes->allows(Toggle::Sampled) => $this->notLet(Toggle::Sampled),
            $verdict->wasCutShort() && ! $writes->allows(Toggle::CutShort) => $this->notLet(Toggle::CutShort),
            $passed && $writes->passing() === Passing::Never => NotWritten::because(self::KEPT),
            default => NotGiven::value(),
        };
    }

    private function notLet(Toggle $toggle): NotWritten
    {
        return NotWritten::because(sprintf(self::NOT_LET, $toggle->value));
    }
}
