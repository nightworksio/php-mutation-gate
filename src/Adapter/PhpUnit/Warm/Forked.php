<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/**
 * What a mutation run's warm workers came to: each mutant a child judged, by
 * its position among the runs, with the evidence of each kill, and a warning
 * for each worker whose boot the guard refused. A run no child judged is left to a fresh process.
 */
final readonly class Forked
{
    /** @param array<int, Mutant> $judged by position */
    private function __construct(private array $judged, private Warnings $warnings, private Evidences $evidence)
    {
    }

    /** @param array<int, Mutant> $judged by position */
    public static function of(array $judged, Warnings $warnings, Evidences $evidence): self
    {
        return new self($judged, $warnings, $evidence);
    }

    /** Nothing forked: every run left to a fresh process, and nothing to warn of. */
    public static function nothing(): self
    {
        return new self([], Warnings::none(), Evidences::none());
    }

    /** The evidence of the kills the children judged. */
    public function evidence(): Evidences
    {
        return $this->evidence;
    }

    /** The mutant at a position, as a child judged it; or nothing, where none did. */
    public function judgedAt(int $at): Mutant|NotGiven
    {
        return array_key_exists($at, $this->judged) ? $this->judged[$at] : NotGiven::value();
    }

    public function warnings(): Warnings
    {
        return $this->warnings;
    }
}
