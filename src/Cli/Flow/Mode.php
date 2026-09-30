<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Proof\Passed;

/**
 * Which units a run considers: every one, as `--full` asks and as a run with
 * no base does, or those a change since a ref reaches, as `--changed-since`
 * asks. `last-passed` is the newest commit of the run's own scope whose
 * verdict passed, and a run with none recorded is full.
 */
final readonly class Mode
{
    public const string LAST_PASSED = 'last-passed';

    private function __construct(private string $since)
    {
    }

    public static function full(): self
    {
        return new self('');
    }

    /** Change-scoped, since a ref git resolves or `last-passed`. */
    public static function since(string $ref): self
    {
        return new self($ref);
    }

    /** The revision the change is read since, or why the run is full. */
    public function base(Ledgers $ledgers): Revision|CannotTell
    {
        return match ($this->since) {
            '' => CannotTell::because('A full run considers every unit.'),
            self::LAST_PASSED => $this->lastPassed($ledgers),
            default => Revision::ref($this->since),
        };
    }

    private function lastPassed(Ledgers $ledgers): Revision|CannotTell
    {
        $passed = $ledgers->lastPassed();

        return $passed instanceof Passed
            ? $passed->commit()
            : CannotTell::because('No commit of this scope has passed yet, so the run considers every unit.');
    }
}
