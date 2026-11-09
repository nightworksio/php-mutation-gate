<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\DefaultBranchSince;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Judged;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Proof\TakenProofs;

/**
 * Which units a run considers: every one, as `--full` asks and as a run with
 * no base does, or those a change since a ref reaches, as `--changed-since`
 * asks. `last-passed` is the newest commit of the run's own scope whose
 * verdict passed, and a run with none recorded is full. `last-run`, off the
 * default branch, reads the change from the commit its scope's last run
 * judged, where that run was of the same kind and reported under the same
 * check, with the fetched default branch giving the new code; where there is
 * no such run, it reads the change since the fetched default branch. On the
 * default branch it reads the change since `last-passed` (ADR-0005).
 *
 * @internal
 */
final readonly class Mode
{
    public const string LAST_PASSED = 'last-passed';

    public const string LAST_RUN = 'last-run';

    private const string NONE_PASSED = 'No commit of this scope has passed yet, so the run considers every unit.';

    private const string ONLY_A_RUN = '`last-run` names the commit a run of the gate judged, so only `run` reads it.';

    private function __construct(
        private string $since,
        private bool $sampled,
        private bool $unproven = false,
        private bool $byItself = false,
    ) {
    }

    public static function full(): self
    {
        return new self('', sampled: false);
    }

    /** Every unit, taking no proof, so every unit runs (ADR-0084). */
    public static function unproven(): self
    {
        return new self('', sampled: false, unproven: true);
    }

    /** Change-scoped, since a ref git resolves, `last-passed` or `last-run`. */
    public static function since(string $ref): self
    {
        return new self($ref, sampled: false);
    }

    /** This mode, sampling the units no proof covers. */
    public function sampled(): self
    {
        return new self($this->since, sampled: true, unproven: $this->unproven);
    }

    /**
     * This mode, sampling of its own accord as `sampling.auto` decides
     * (ADR-0086): the reached units that hold no new code are sampled too.
     */
    public function sampledByItself(): self
    {
        return new self($this->since, sampled: true, unproven: $this->unproven, byItself: true);
    }

    /** Whether the reached units that hold no new code are sampled too, as a run sampled of itself does. */
    public function samplesReached(): bool
    {
        return $this->byItself;
    }

    /** Whether the units no proof covers are sampled, as `--sample` asks. */
    public function isSampled(): bool
    {
        return $this->sampled;
    }

    /** Whether the change is read since the newest commit of the run's scope whose verdict passed, as by default. */
    public function isSinceLastPassed(): bool
    {
        return $this->since === self::LAST_PASSED;
    }

    /** Which proofs the run takes: none, as `--unproven` asks, or those the config has it take. */
    public function takes(TakenProofs $configured): TakenProofs
    {
        return $this->unproven ? TakenProofs::None : $configured;
    }

    /** Whether every unit is considered, as `--full` asks. */
    public function isFull(): bool
    {
        return $this->since === '';
    }

    /** The revision the change is read since, for a command that lists what a change reaches; or why it cannot. */
    public function base(Ledgers $ledgers): Revision|CannotTell
    {
        return match ($this->since) {
            '' => CannotTell::because('A full run considers every unit.'),
            self::LAST_PASSED => $this->lastPassed($ledgers),
            self::LAST_RUN => CannotTell::because(self::ONLY_A_RUN),
            default => Revision::ref($this->since),
        };
    }

    /** What a run of this kind, reporting under this check, reads its change since; or why it is full. */
    public function changeBase(
        Ledgers $ledgers,
        Standing $standing,
        RunProfile $kind,
        string $check,
        DefaultBranchSince $since,
    ): ChangeBase|CannotTell {
        $base = match ($this->since) {
            self::LAST_RUN => $this->fallback($ledgers, $standing),
            default => $this->base($ledgers),
        };
        $judged = $this->judgedSince($ledgers, $since);
        $lastRun = $ledgers->lastRun();
        $exact = $this->since === self::LAST_RUN
            && $ledgers->readsOwnScope()
            && $lastRun instanceof LastRun
            && $lastRun->check() === $check
            && $lastRun->profile()->equals($kind);

        return match (true) {
            ! $base instanceof Revision => $base,
            $exact => $this->sinceLedger(ChangeBase::lastRun($lastRun->judged(), $base), $ledgers, $judged),
            $judged instanceof Judged && $this->isSinceLastPassed() && ! $ledgers->readsOwnScope()
                => ChangeBase::since($judged->commit())->withOpenSince($base, $judged->open()),
            default => $this->sinceLedger(ChangeBase::since($base), $ledgers, $judged),
        };
    }

    /**
     * The default branch's newest judged run its changes are read since, as `reach.defaultBranchSince` says: where
     * the run reads since judged runs, the ledger recorded one newer than its last pass, under the same check, and
     * it passed at all (ADR-0141); none otherwise, every change read since the last pass.
     */
    private function judgedSince(Ledgers $ledgers, DefaultBranchSince $since): Judged|NotGiven
    {
        $runs = $ledgers->defaultBranch()->runs();
        $judged = $runs->judged();
        $passed = $runs->passed();

        return $since->readsJudged(! $ledgers->readsOwnScope())
            && $judged instanceof Judged
            && $passed instanceof Passed
            && $judged->check() === $passed->check()
            && $judged->isNewerThan($passed)
            ? $judged
            : NotGiven::value();
    }

    /**
     * Off the default branch, this base with the commit the default branch's
     * ledger last passed at, whose results the run carries (ADR-0060); or, where
     * it reads since a newer judged run, that run's commit, the units it left
     * open reading since the last pass (ADR-0141).
     */
    private function sinceLedger(ChangeBase $base, Ledgers $ledgers, Judged|NotGiven $judged): ChangeBase
    {
        $passed = $ledgers->defaultBranch()->runs()->passed();

        return match (true) {
            ! $ledgers->readsOwnScope() || ! $passed instanceof Passed => $base,
            $judged instanceof Judged => $base
                ->withLedgerAt($judged->commit())
                ->withOpenSince($passed->commit(), $judged->open()),
            default => $base->withLedgerAt($passed->commit()),
        };
    }

    /**
     * What `last-run` falls back to: off the default branch, the fetched branch the run is read against; on it,
     * the last commit that passed on it.
     */
    private function fallback(Ledgers $ledgers, Standing $standing): Revision|CannotTell
    {
        return $ledgers->readsOwnScope() ? $standing->fetchedBase() : $this->lastPassed($ledgers);
    }

    private function lastPassed(Ledgers $ledgers): Revision|CannotTell
    {
        $passed = $ledgers->lastPassed();

        return $passed instanceof Passed ? $passed->commit() : CannotTell::because(self::NONE_PASSED);
    }
}
