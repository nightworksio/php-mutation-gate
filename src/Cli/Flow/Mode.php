<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;

/**
 * Which units a run considers: every one, as `--full` asks and as a run with
 * no base does, or those a change since a ref reaches, as `--changed-since`
 * asks. `last-passed` is the newest commit of the run's own scope whose
 * verdict passed, and a run with none recorded is full. `last-run`, off the
 * default branch, reads the change from the commit its scope's last run
 * judged, where that run was of the same kind and reported under the same
 * check, with the fetched default branch giving the new code; where there is
 * no such run, it reads the change since the fetched default branch. On the
 * default branch it reads the change since `last-passed` (ADR-0005, decision
 * 2).
 */
final readonly class Mode
{
    public const string LAST_PASSED = 'last-passed';

    public const string LAST_RUN = 'last-run';

    private const string NONE_PASSED = 'No commit of this scope has passed yet, so the run considers every unit.';

    private const string ONLY_A_RUN = '`last-run` names the commit a run of the gate judged, so only `run` reads it.';

    private function __construct(private string $since)
    {
    }

    public static function full(): self
    {
        return new self('');
    }

    /** Change-scoped, since a ref git resolves, `last-passed` or `last-run`. */
    public static function since(string $ref): self
    {
        return new self($ref);
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
    ): ChangeBase|CannotTell {
        $base = match ($this->since) {
            self::LAST_RUN => $this->fallback($ledgers, $standing),
            default => $this->base($ledgers),
        };
        $lastRun = $ledgers->lastRun();
        $exact = $this->since === self::LAST_RUN
            && $ledgers->readsOwnScope()
            && $lastRun instanceof LastRun
            && $lastRun->check() === $check
            && $lastRun->profile()->equals($kind);

        return match (true) {
            ! $base instanceof Revision => $base,
            $exact => ChangeBase::lastRun($lastRun->judged(), $base),
            default => ChangeBase::since($base),
        };
    }

    /** What `last-run` falls back to: the fetched default branch off it, the last commit that passed on it. */
    private function fallback(Ledgers $ledgers, Standing $standing): Revision|CannotTell
    {
        return $ledgers->readsOwnScope() ? $standing->fetchedDefaultBranch() : $this->lastPassed($ledgers);
    }

    private function lastPassed(Ledgers $ledgers): Revision|CannotTell
    {
        $passed = $ledgers->lastPassed();

        return $passed instanceof Passed ? $passed->commit() : CannotTell::because(self::NONE_PASSED);
    }
}
