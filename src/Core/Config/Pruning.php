<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function is_int;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Pruning\Window;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * Whether a mutator that let no mutant through lately is pruned on unchanged
 * code, how many of its newest mutants say so, and how old a result it
 * carries may be (ADR-0025, decisions 2 to 4): `pruning`, which the `Reach`
 * part holds, as it decides which mutants a reached unit makes.
 */
final readonly class Pruning
{
    /** How many of a mutator's newest judged mutants must all be killed before it is pruned. */
    private const int WINDOW = 500;

    /** How old the newest full result of a unit may be before every mutator runs on it again: a week. */
    private const int AUDIT_DAYS = 7;

    private function __construct(
        private bool|Absent $enabled,
        private int|Absent $window,
        private Seconds|Absent $audit,
    ) {
    }

    public static function of(
        bool|Absent $enabled = new Absent(),
        int|Absent $window = new Absent(),
        Seconds|Absent $audit = new Absent(),
    ): self {
        return new self($enabled, $window, $audit);
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->enabled, $later->enabled),
            Absent::laid($this->window, $later->window),
            Absent::laid($this->audit, $later->audit),
        );
    }

    /** `pruning.enabled`: whether any mutator is pruned. */
    public function enabled(): bool
    {
        return $this->enabled instanceof Absent || $this->enabled;
    }

    /** `pruning.window`: how many of a mutator's newest judged mutants must all be killed before it is pruned. */
    public function window(): Window
    {
        return Window::of($this->window instanceof Absent ? self::WINDOW : $this->window);
    }

    /** `pruning.audit`: how old a unit's newest full result may be before every mutator runs on it again. */
    public function audit(): Seconds
    {
        return $this->audit instanceof Seconds ? $this->audit : Seconds::days(self::AUDIT_DAYS);
    }

    /** The `pruning` member a config writes; nothing where no key is set. */
    public function written(): Member
    {
        return Member::unlessEmpty(
            'pruning',
            Json::object(
                Member::of('enabled', $this->enabled),
                Member::of('window', $this->window),
                Member::of('audit', $this->audit instanceof Seconds ? $this->audit->written() : $this->audit),
            ),
        );
    }

    /** The PHP builder's calls for the keys set. */
    public function php(): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->enabled === false ? ['Pruning::off()'] : [],
            ...$this->enabled === true ? ['Pruning::on()'] : [],
            ...is_int($this->window) ? [sprintf('Pruning::window(%d)', $this->window)] : [],
            ...$this->audit instanceof Seconds
                ? [sprintf('Pruning::auditEvery(%s)', PhpCalls::literal($this->audit->written()))]
                : [],
        ]);
    }
}
