<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use NightWorksIO\MutationGate\Core\Format\JsonDocument;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * A key and its value moved anywhere else in the file, with each object the
 * new place needs; an object the old place leaves empty goes too. Where the
 * new key is already written, nothing is changed, and the step is left for a
 * hand edit.
 */
final readonly class Move implements Step
{
    private function __construct(private KeyPath $from, private KeyPath $to, private Spelling|NotGiven $spelling)
    {
    }

    public static function of(string $from, string $to): self
    {
        return new self(KeyPath::of($from), KeyPath::of($to), NotGiven::value());
    }

    /** This move, retiring a builder call for another. */
    public function spelt(Spelling $spelling): self
    {
        return new self($this->from, $this->to, $spelling);
    }

    public function applied(JsonDocument $file): JsonDocument
    {
        return $file->has($this->to) ? $file : $file->moved($this->from, $this->to);
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
        return Changes::became($this->from, $this->to);
    }

    public function spelling(): Spelling|NotGiven
    {
        return $this->spelling;
    }
}
