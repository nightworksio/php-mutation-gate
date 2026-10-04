<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpStan;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;

/** How long one of PHPStan's checks may take, `staticCheck.seconds` (ADR-0020, decision 8), or no limit. */
final readonly class CheckLimit
{
    /** Why a check stopped at its limit cannot judge. */
    private const string UNFINISHED = 'PHPStan did not finish a check in %s.';

    /** What Symfony's Process reads as no limit. */
    private const float NONE = 0.0;

    /** This limit, or none where none is given. */
    public function __construct(private Seconds|Unlimited $limit = new Unlimited())
    {
    }

    /** The limit as Symfony's Process takes it: seconds, or 0, which it reads as none. */
    public function timeout(): float
    {
        return $this->limit instanceof Seconds ? $this->limit->seconds() : self::NONE;
    }

    /** A run, or why it cannot judge where it did not run, or was stopped at the limit. */
    public function judged(ChildProcess|CannotJudge $ran): ChildProcess|CannotJudge
    {
        return $ran instanceof ChildProcess && $ran->wasStopped() && $this->limit instanceof Seconds
            ? CannotJudge::because(sprintf(self::UNFINISHED, $this->limit->written()))
            : $ran;
    }
}
