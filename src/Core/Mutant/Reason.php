<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

/**
 * Why a runner left a mutant unjudged, in the sentence a report prints beside
 * it, and the time budget that ran out before it, where one did (ADR-0008,
 * decision 1).
 */
final readonly class Reason
{
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
