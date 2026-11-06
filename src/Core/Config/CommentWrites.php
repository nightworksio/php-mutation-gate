<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function in_array;

use NightWorksIO\MutationGate\Core\Comment\Key;
use NightWorksIO\MutationGate\Core\Comment\Mode;
use NightWorksIO\MutationGate\Core\Comment\Occasion;
use NightWorksIO\MutationGate\Core\Comment\Passing;
use NightWorksIO\MutationGate\Core\Comment\State;
use NightWorksIO\MutationGate\Core\Comment\Toggle;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * When the pull request comment is written (ADR-0102): one comment updated in place or a new one each run; the
 * states that write it; what a passing run writes; which kinds of run comment; and whether a draft's, a sampled and
 * a cut-short run comment.
 */
final readonly class CommentWrites
{
    /** What this part says of, of the toggles. */
    private const array TOGGLES = [Toggle::Drafts, Toggle::Sampled, Toggle::CutShort];

    /**
     * @param Listed<State>|Absent    $states
     * @param Listed<Occasion>|Absent $on
     */
    private function __construct(
        private Mode|Absent $mode,
        private Listed|Absent $states,
        private Passing|Absent $passing,
        private Listed|Absent $on,
        private CommentToggles $toggles,
    ) {
    }

    /**
     * @param Listed<State>|Absent    $states
     * @param Listed<Occasion>|Absent $on
     */
    public static function of(
        Mode|Absent $mode = new Absent(),
        Listed|Absent $states = new Absent(),
        Passing|Absent $passing = new Absent(),
        Listed|Absent $on = new Absent(),
        CommentToggles|Absent $toggles = new Absent(),
    ): self {
        return new self(
            $mode,
            $states,
            $passing,
            $on,
            $toggles instanceof CommentToggles ? $toggles : CommentToggles::none(),
        );
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            $later->mode instanceof Absent ? $this->mode : $later->mode,
            $later->states instanceof Absent ? $this->states : $later->states,
            $later->passing instanceof Absent ? $this->passing : $later->passing,
            $later->on instanceof Absent ? $this->on : $later->on,
            $this->toggles->over($later->toggles),
        );
    }

    /** `comment.mode`: how a run's comment meets the comments before it. */
    public function mode(): Mode
    {
        return $this->mode instanceof Mode ? $this->mode : Mode::Sticky;
    }

    /** `comment.states`: whether the comment is written in a state; in every one by default. */
    public function writes(State $state): bool
    {
        return ! $this->states instanceof Listed || in_array($state, [...$this->states], strict: true);
    }

    /** `comment.passing`: what a passing run writes. */
    public function passing(): Passing
    {
        return $this->passing instanceof Passing ? $this->passing : Passing::Update;
    }

    /**
     * `comment.on`: whether a kind of run writes the comment, where the reporter's own default is these.
     *
     * @param Listed<Occasion> $standard
     */
    public function commentsOn(Occasion $occasion, Listed $standard): bool
    {
        return in_array($occasion, [...$this->on instanceof Listed ? $this->on : $standard], strict: true);
    }

    /** `comment.drafts`, `.sampled` and `.cutShort`: whether such a run comments; each does by default. */
    public function allows(Toggle $toggle): bool
    {
        return $this->toggles->allows($toggle);
    }

    /** The keys a config writes of it, those set. */
    public function json(): Json
    {
        return Json::object(
            Member::of('mode', $this->mode instanceof Mode ? $this->mode->value : $this->mode),
            Member::of('states', CommentWritten::listed($this->states)),
            Member::of('passing', $this->passing instanceof Passing ? $this->passing->value : $this->passing),
            Member::of('on', CommentWritten::listed($this->on)),
            ...CommentWritten::toggles($this->toggles, self::TOGGLES),
        );
    }

    /** The PHP builder's calls for the keys set. */
    public function php(): PhpCalls
    {
        return PhpCalls::inWith(
            ...CommentWritten::caseCall(Key::Mode, $this->mode),
            ...CommentWritten::casesCall(Key::States, $this->states),
            ...CommentWritten::caseCall(Key::Passing, $this->passing),
            ...CommentWritten::casesCall(Key::On, $this->on),
            ...CommentWritten::togglesCall($this->toggles, self::TOGGLES),
        );
    }
}
