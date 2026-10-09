<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use function array_values;

use NightWorksIO\MutationGate\Core\Format\JsonDocument;
use NightWorksIO\MutationGate\Core\Format\JsonFragment;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * An object whose named parts each move to a key of their own. A part it
 * does not name stays where it was, and a scalar cannot be split: either is
 * left for a hand edit (ADR-0026, decision 2).
 */
final readonly class Split implements Step
{
    /** @param non-empty-list<SplitPart> $parts */
    private function __construct(private KeyPath $from, private array $parts, private Spelling|NotGiven $spelling)
    {
    }

    public static function of(string $from, SplitPart $part, SplitPart ...$more): self
    {
        return new self(KeyPath::of($from), [$part, ...array_values($more)], NotGiven::value());
    }

    /** This split, retiring a builder call, which a hand edit replaces (ADR-0026, decision 3). */
    public function spelt(Spelling $spelling): self
    {
        return new self($this->from, $this->parts, $spelling);
    }

    public function applied(JsonDocument $file): JsonDocument
    {
        $written = $file->fragment($this->from);

        if (! $written instanceof JsonFragment || ! $written->isObject()) {
            return $file;
        }

        foreach ($this->parts as $part) {
            $file = $file->has($part->to()) ? $file : $file->moved($part->under($this->from), $part->to());
        }

        return $file;
    }

    public function appliesTo(JsonDocument $file): bool
    {
        return $file->has($this->from);
    }

    public function at(): KeyPath
    {
        return $this->from;
    }

    public function change(): string
    {
        $to = [];

        foreach ($this->parts as $part) {
            $to[] = $part->to();
        }

        return Changes::split($this->from, ...$to);
    }

    public function spelling(): Spelling|NotGiven
    {
        return $this->spelling;
    }
}
