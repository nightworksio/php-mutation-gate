<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;

/**
 * What a runner's unmutated controls find, running while the gate goes on,
 * taken once the gate needs it; stopped where the gate needs it no more.
 *
 * @internal
 */
final readonly class PendingControls
{
    /**
     * @param Closure(): (ControlRuns|CannotJudge) $taking   what the controls found, waited for
     * @param Closure(): void                      $stopping every control still running stopped
     */
    private function __construct(private Closure $taking, private Closure $stopping)
    {
    }

    /**
     * @param Closure(): (ControlRuns|CannotJudge) $taking   what the controls found, waited for
     * @param Closure(): void                      $stopping every control still running stopped
     */
    public static function taking(Closure $taking, Closure $stopping): self
    {
        return new self($taking, $stopping);
    }

    /** What the controls found already, or why none could run. */
    public static function ran(ControlRuns|CannotJudge $runs): self
    {
        return new self(static fn(): ControlRuns|CannotJudge => $runs, static function (): void {
        });
    }

    /** What each control found, or why the runner runs none, once every one has ended. */
    public function taken(): ControlRuns|CannotJudge
    {
        return ($this->taking)();
    }

    /** Every control still running stopped, with every process it started. */
    public function stop(): void
    {
        ($this->stopping)();
    }
}
