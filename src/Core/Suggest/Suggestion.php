<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Suggest;

use NightWorksIO\MutationGate\Core\Config\Layer;

/**
 * One change `config:suggest` proposes (ADR-0076): the evidence it rests on, as a line a person reads, and what
 * it lays over the config file, which nothing writes.
 *
 * @internal
 */
final readonly class Suggestion
{
    private function __construct(private string $why, private Layer $change)
    {
    }

    public static function of(string $why, Layer $change): self
    {
        return new self($why, $change);
    }

    /** Why it is suggested: the evidence it rests on, as one line. */
    public function why(): string
    {
        return $this->why;
    }

    /** What the suggestion lays over the config file. */
    public function change(): Layer
    {
        return $this->change;
    }
}
