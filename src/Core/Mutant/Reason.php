<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

/**
 * Why a mutant stands as it does, in the sentence a report prints beside it:
 * why a runner left it unjudged, and the time budget that ran out before it,
 * where one did (ADR-0008, decision 1), or why the config ignores it
 * (decision 4).
 */
final readonly class Reason
{
    /**
     * Why a mutant of a line that is not executable is unjudged where no test
     * reads its value (ADR-0004), which a test that references the value
     * judges (ADR-0015, decision 1).
     */
    public const string UNREACHED = 'no test reaches this value';

    private function __construct(private string $text, private OutOfTime|Unreported $outOfTime)
    {
    }

    public static function that(string $text): self
    {
        return new self($text, Unreported::reason());
    }

    /** The reason a time budget that ran out before this left a mutant unjudged, in these words. */
    public static function ranOutOf(OutOfTime $before, string $text): self
    {
        return new self($text, $before);
    }

    /** Whether it says no test reaches the mutant's value, as the ledger keeps it, in its words. */
    public function isUnreached(): bool
    {
        return $this->text === self::UNREACHED;
    }

    public function text(): string
    {
        return $this->text;
    }

    /** What a time budget ran out before, where one left the mutant unjudged. */
    public function outOfTime(): OutOfTime|Unreported
    {
        return $this->outOfTime;
    }
}
