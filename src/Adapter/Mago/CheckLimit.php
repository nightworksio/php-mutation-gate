<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Mago;

use function hrtime;
use function max;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * A check's limit, `staticCheck.seconds` (ADR-0020, decision 8), counted
 * from the check's start on the monotonic clock, as Mago spends it over
 * the listing of its files and the analysis.
 */
final readonly class CheckLimit
{
    /** Why a check stopped at its limit cannot judge. */
    private const string UNFINISHED = 'Mago did not finish a check in %s.';

    /** The least a command is given: Symfony's Process reads a limit of 0 as none. */
    private const float LEAST = 0.001;

    private function __construct(private Seconds $limit, private int|float $started)
    {
    }

    /** This limit, counted from now. */
    public static function from(Seconds $limit): self
    {
        return new self($limit, hrtime(as_number: true));
    }

    /** The seconds left of the limit, and a millisecond once it has passed, which stops a command at once. */
    public function left(): Seconds
    {
        $spent = (hrtime(as_number: true) - $this->started) / Seconds::NANOSECONDS;

        return Seconds::of(max(self::LEAST, $this->limit->seconds() - $spent));
    }

    /** Why the check, stopped at the limit, cannot judge. */
    public function unfinished(): CannotJudge
    {
        return CannotJudge::because(sprintf(self::UNFINISHED, $this->limit->written()));
    }
}
