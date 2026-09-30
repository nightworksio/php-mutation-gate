<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;

/** A mutant's status as Pest names it in the plugin's records, and, but for one word, on its summary line. */
enum PestStatus: string
{
    /** A test failed against the mutant. */
    case Tested = 'tested';

    /** Every test that covers the mutant passed. */
    case Untested = 'untested';

    /** No test covers the mutant. */
    case Uncovered = 'uncovered';

    /** The mutant's own process ran past Pest's limit. */
    case Timeout = 'timeout';

    /** Pest never ran the mutant. */
    case None = 'none';

    /** How Pest's summary line names a mutant it never ran. */
    private const string PENDING = 'pending';

    /** A status as Pest's summary line names it. */
    public static function fromSummary(string $word): self
    {
        return $word === self::PENDING ? self::None : self::from($word);
    }

    /** How Pest's summary line names the status. */
    public function onSummary(): string
    {
        return $this === self::None ? self::PENDING : $this->value;
    }

    /** The gate's status for a mutant Pest ended with this one. */
    public function status(): MutantStatus
    {
        return match ($this) {
            self::Tested => MutantStatus::Killed,
            self::Untested => MutantStatus::Survived,
            self::Uncovered => MutantStatus::Uncovered,
            self::Timeout => MutantStatus::TimedOut,
            self::None => MutantStatus::Unjudged,
        };
    }
}
