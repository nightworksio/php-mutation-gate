<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\IdenticalReuse;
use NightWorksIO\MutationGate\Core\Config\OwnScopeTrust;
use NightWorksIO\MutationGate\Core\Format\Json;

/** What a run takes from an earlier passed verdict (ADR-0139, ADR-0198): `reuse`. */
final readonly class Reuse implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /**
     * `reuse.identical`: `on`, as by default: a verdict whose every input is identical stands on pull requests and
     * on the default branch.
     */
    public static function identical(): self
    {
        return new self(Json::at('reuse.identical', IdenticalReuse::On->value));
    }

    /**
     * `reuse.identical`: `pulls`: such a verdict stands on pull requests alone, and the default branch always
     * judges.
     */
    public static function identicalOnPullRequests(): self
    {
        return new self(Json::at('reuse.identical', IdenticalReuse::Pulls->value));
    }

    /** `reuse.identical`: `off`: every run judges. */
    public static function neverIdentical(): self
    {
        return new self(Json::at('reuse.identical', IdenticalReuse::Off->value));
    }

    /** `reuse.forks`: true: a verdict or results a fork's run recorded are reused too, which trusts that run. */
    public static function fromForks(): self
    {
        return new self(Json::at('reuse.forks', value: true));
    }

    /** `reuse.forks`: false, as by default: nothing a fork's run recorded is read. */
    public static function notFromForks(): self
    {
        return new self(Json::at('reuse.forks', value: false));
    }

    /**
     * `reuse.ownScopeProofs`: `reach`, as by default: a merged pull request's pass carries its own results, and the
     * units it took from its own scope's proofs are run again.
     */
    public static function reachingOwnScopeProofs(): self
    {
        return new self(Json::at('reuse.ownScopeProofs', OwnScopeTrust::Reach->value));
    }

    /** `reuse.ownScopeProofs`: `refuse`: a merged pull request's pass that took any own-scope proof spares nothing. */
    public static function refusingOwnScopeProofs(): self
    {
        return new self(Json::at('reuse.ownScopeProofs', OwnScopeTrust::Refuse->value));
    }

    /** `reuse.ownScopeProofs`: `trust`: a merged pull request's pass is taken whole, own-scope proofs included. */
    public static function trustingOwnScopeProofs(): self
    {
        return new self(Json::at('reuse.ownScopeProofs', OwnScopeTrust::Trust->value));
    }

    /**
     * `reuse.delta`: true: where the landed tree differs from the head a merged pull request passed at, the default
     * branch reaches only the change from that head to it.
     */
    public static function delta(): self
    {
        return new self(Json::at('reuse.delta', value: true));
    }

    /**
     * `reuse.delta`: false, as by default: such a tree's merged range is judged, taking the proofs whose keys
     * match.
     */
    public static function noDelta(): self
    {
        return new self(Json::at('reuse.delta', value: false));
    }

    /** `reuse.environment`: the variables whose values are among a run's inputs. */
    public static function environment(string ...$names): self
    {
        return new self(Json::at('reuse.environment', Json::items(...$names)));
    }

    /** `reuse.passes`: how many earlier passes that record their inputs a ledger keeps for reuse. */
    public static function passes(int $passes): self
    {
        return new self(Json::at('reuse.passes', $passes));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
