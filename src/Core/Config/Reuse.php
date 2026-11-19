<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function is_int;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Proof\LedgerRetention;

use function sprintf;

/**
 * What a run takes from an earlier passed verdict (ADR-0139, ADR-0198):
 * `reuse`, which the `Ci` part holds, as it decides how a run goes.
 * `identical`, where a verdict whose every input is identical stands;
 * `forks`, whether a record a fork's run wrote is read at all;
 * `ownScopeProofs`, what a merged pull request's pass that took results from
 * its own scope's proofs is trusted for; `delta`, whether a landed tree that
 * differs from the head a merged pull request passed at reaches only the
 * change from that head; `environment`, the variables whose values are
 * among a run's inputs; and `passes`, how many earlier passes that record
 * their inputs a ledger keeps for reuse.
 */
final readonly class Reuse
{
    /**
     * @param Listed<string>|Absent $environment
     */
    private function __construct(
        private IdenticalReuse|Absent $identical,
        private bool|Absent $forks,
        private OwnScopeTrust|Absent $ownScopeProofs,
        private bool|Absent $delta,
        private Listed|Absent $environment,
        private int|Absent $passes,
    ) {
    }

    /** @param Listed<string>|Absent $environment */
    public static function of(
        IdenticalReuse|Absent $identical = new Absent(),
        bool|Absent $forks = new Absent(),
        OwnScopeTrust|Absent $ownScopeProofs = new Absent(),
        bool|Absent $delta = new Absent(),
        Listed|Absent $environment = new Absent(),
        int|Absent $passes = new Absent(),
    ): self {
        return new self($identical, $forks, $ownScopeProofs, $delta, $environment, $passes);
    }

    /** Every key at the value it takes when every layer leaves it out. */
    public static function standard(): self
    {
        $none = self::of();

        return self::of(
            $none->identical(),
            $none->forks(),
            $none->ownScopeProofs(),
            $none->delta(),
            $none->environment(),
            $none->passes(),
        );
    }

    /** This, with what a later layer sets laid over it, key by key; the variables of both, each once. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->identical, $later->identical),
            Absent::laid($this->forks, $later->forks),
            Absent::laid($this->ownScopeProofs, $later->ownScopeProofs),
            Absent::laid($this->delta, $later->delta),
            Listed::joined($this->environment, $later->environment, static fn(string $name): string => $name),
            Absent::laid($this->passes, $later->passes),
        );
    }

    /** `reuse.identical`: `on` unless the config says. */
    public function identical(): IdenticalReuse
    {
        return $this->identical instanceof IdenticalReuse ? $this->identical : IdenticalReuse::On;
    }

    /** `reuse.forks`: whether a record a fork's run wrote is read; false unless the config says. */
    public function forks(): bool
    {
        return $this->forks === true;
    }

    /** `reuse.ownScopeProofs`: `reach` unless the config says. */
    public function ownScopeProofs(): OwnScopeTrust
    {
        return $this->ownScopeProofs instanceof OwnScopeTrust ? $this->ownScopeProofs : OwnScopeTrust::Reach;
    }

    /**
     * `reuse.delta`: whether a landed tree that differs reaches only the change from the judged head; false unless
     * the config says.
     */
    public function delta(): bool
    {
        return $this->delta === true;
    }

    /**
     * `reuse.environment`: the variables whose values are among a run's inputs; none unless the config says.
     *
     * @return Listed<string>
     */
    public function environment(): Listed
    {
        return $this->environment instanceof Listed ? $this->environment : Listed::of();
    }

    /**
     * `reuse.passes`: how many earlier passes that record their inputs a ledger keeps for reuse; ten unless the
     * config says.
     */
    public function passes(): int
    {
        return $this->passes instanceof Absent ? LedgerRetention::PASSED : $this->passes;
    }

    /** The `reuse` member a config writes; nothing where no key is set. */
    public function written(): Member
    {
        return Member::unlessEmpty(
            'reuse',
            Json::object(
                Member::of(
                    'identical',
                    $this->identical instanceof IdenticalReuse ? $this->identical->value : $this->identical,
                ),
                Member::of('forks', $this->forks),
                Member::of(
                    'ownScopeProofs',
                    $this->ownScopeProofs instanceof OwnScopeTrust
                        ? $this->ownScopeProofs->value
                        : $this->ownScopeProofs,
                ),
                Member::of('delta', $this->delta),
                Member::of(
                    'environment',
                    $this->environment instanceof Listed ? Json::items(...$this->environment) : $this->environment,
                ),
                Member::of('passes', $this->passes),
            ),
        );
    }

    /** The PHP builder's calls for the keys set. */
    public function php(): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->identical instanceof IdenticalReuse ? [$this->identical->php()] : [],
            ...$this->ownScopeProofs instanceof OwnScopeTrust ? [$this->ownScopeProofs->php()] : [],
        ])
            ->and(PhpCalls::flag($this->forks, 'Reuse::fromForks()', 'Reuse::notFromForks()'))
            ->and(PhpCalls::flag($this->delta, 'Reuse::delta()', 'Reuse::noDelta()'))
            ->and(PhpCalls::inWith(...$this->environment instanceof Listed
                ? [sprintf('Reuse::environment(%s)', PhpCalls::literals(...$this->environment))]
                : []))
            ->and(PhpCalls::inWith(...is_int($this->passes) ? [sprintf('Reuse::passes(%d)', $this->passes)] : []));
    }
}
