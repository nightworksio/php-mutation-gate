<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

use NightWorksIO\MutationGate\Core\File\Digest;

use function sprintf;
use function strcmp;
use function usort;

/**
 * The order a sample takes a tree's units in (ADR-0020, decision 28): by
 * the SHA-256 of the seed followed by the unit's path, a digest rather than
 * a random number, so the same seed always draws the same units.
 */
final readonly class Draw
{
    private function __construct(private string $seed)
    {
    }

    public static function seeded(string $seed): self
    {
        return new self($seed);
    }

    /** @return list<Candidate> these candidates, in the order the draw takes them */
    public function ranked(Candidate ...$candidates): array
    {
        $ranked = $candidates;
        usort(
            $ranked,
            fn(Candidate $one, Candidate $other): int => strcmp($this->digestOf($one), $this->digestOf($other)),
        );

        return $ranked;
    }

    private function digestOf(Candidate $candidate): string
    {
        return Digest::sha256Of(sprintf('%s%s', $this->seed, $candidate->unit()->path()->value()))->value();
    }
}
