<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_key_exists;
use function in_array;
use function is_array;

use NightWorksIO\MutationGate\Core\Comment\Key;
use NightWorksIO\MutationGate\Core\Comment\Mode;
use NightWorksIO\MutationGate\Core\Comment\Occasion;
use NightWorksIO\MutationGate\Core\Comment\Passing;
use NightWorksIO\MutationGate\Core\Comment\State;
use NightWorksIO\MutationGate\Core\Comment\Toggle;
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
     * @param list<State>|Absent    $states
     * @param list<Occasion>|Absent $on
     * @param array<string, bool>   $toggles by the toggle's name, those set
     */
    private function __construct(
        private Mode|Absent $mode,
        private array|Absent $states,
        private Passing|Absent $passing,
        private array|Absent $on,
        private array $toggles,
    ) {
    }

    /**
     * @param list<State>|Absent    $states
     * @param list<Occasion>|Absent $on
     * @param array<string, bool>   $toggles by the toggle's name: `drafts`, `sampled` and `cutShort`
     */
    public static function of(
        Mode|Absent $mode = new Absent(),
        array|Absent $states = new Absent(),
        Passing|Absent $passing = new Absent(),
        array|Absent $on = new Absent(),
        array $toggles = [],
    ): self {
        return new self($mode, $states, $passing, $on, $toggles);
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            $later->mode instanceof Absent ? $this->mode : $later->mode,
            $later->states instanceof Absent ? $this->states : $later->states,
            $later->passing instanceof Absent ? $this->passing : $later->passing,
            $later->on instanceof Absent ? $this->on : $later->on,
            [...$this->toggles, ...$later->toggles],
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
        return ! is_array($this->states) || in_array($state, $this->states, strict: true);
    }

    /** `comment.passing`: what a passing run writes. */
    public function passing(): Passing
    {
        return $this->passing instanceof Passing ? $this->passing : Passing::Update;
    }

    /**
     * `comment.on`: whether a kind of run writes the comment, where the reporter's own default is these.
     *
     * @param list<Occasion> $standard
     */
    public function commentsOn(Occasion $occasion, array $standard): bool
    {
        return in_array($occasion, is_array($this->on) ? $this->on : $standard, strict: true);
    }

    /** `comment.drafts`, `.sampled` and `.cutShort`: whether such a run comments; each does by default. */
    public function allows(Toggle $toggle): bool
    {
        return ! array_key_exists($toggle->value, $this->toggles) || $this->toggles[$toggle->value];
    }

    /** @return list<Member> the keys a config writes of it, those set */
    public function members(): array
    {
        return [
            Member::of('mode', $this->mode instanceof Mode ? $this->mode->value : $this->mode),
            Member::of('states', CommentWritten::listed($this->states)),
            Member::of('passing', $this->passing instanceof Passing ? $this->passing->value : $this->passing),
            Member::of('on', CommentWritten::listed($this->on)),
            ...CommentWritten::toggles($this->toggles, self::TOGGLES),
        ];
    }

    /** @return list<string> the PHP builder's calls for the keys set */
    public function calls(): array
    {
        return [
            ...CommentWritten::caseCall(Key::Mode, $this->mode),
            ...CommentWritten::casesCall(Key::States, $this->states),
            ...CommentWritten::caseCall(Key::Passing, $this->passing),
            ...CommentWritten::casesCall(Key::On, $this->on),
            ...CommentWritten::togglesCall($this->toggles),
        ];
    }
}
