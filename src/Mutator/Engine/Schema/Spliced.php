<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine\Schema;

use NightWorksIO\MutationGate\Core\Format\Bytes;

use function usort;

/**
 * A file's code with each placed site switched in its original's place
 * (ADR-0091): a site within another is switched inside the other's original,
 * and sites of one node one inside the other, each mutant's code held as it
 * was made, with no other site in it. A site that crosses another's edge,
 * which no two nodes do, is left out.
 *
 * @internal the engine's own
 */
final readonly class Spliced
{
    /** @param list<int> $switched the ids of the sites switched */
    private function __construct(private string $text, private array $switched)
    {
    }

    /** The code with these sites switched by this switch, and the ids of those switched. */
    public static function of(string $source, SwitchCall $switch, Placed ...$placed): self
    {
        $sorted = $placed;
        usort($sorted, static fn(Placed $one, Placed $other): int => $one->order() <=> $other->order());
        [$text, $switched] = Splicing::of($source, $switch)->within(0, Bytes::length($source), $sorted);

        return new self($text, $switched);
    }

    public function text(): string
    {
        return $this->text;
    }

    /** @return list<int> */
    public function switched(): array
    {
        return $this->switched;
    }
}
