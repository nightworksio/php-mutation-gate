<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Telemetry;

use NightWorksIO\MutationGate\Core\Cost\Phase;

/**
 * One timed step of a run, as a span of its trace: its name, its id and its
 * parent's, drawn from the trace id and its path so every job names it
 * alike, when it started and how long it took, and its attributes (ADR-0016,
 * decision 15).
 */
final readonly class Span
{
    /** @param array<string, string|int> $attributes by name */
    private function __construct(
        private string $name,
        private string $id,
        private string $parent,
        private Phase $phase,
        private array $attributes,
    ) {
    }

    /**
     * A span with no parent, or under the span with this id.
     *
     * @param array<string, string|int> $attributes by name
     */
    public static function of(string $name, string $id, string $parent, Phase $phase, array $attributes): self
    {
        return new self($name, $id, $parent, $phase, $attributes);
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Its id, 16 lowercase hex digits. */
    public function id(): string
    {
        return $this->id;
    }

    /** Its parent's id; empty for a span at the top of the trace. */
    public function parent(): string
    {
        return $this->parent;
    }

    public function phase(): Phase
    {
        return $this->phase;
    }

    /** @return array<string, string|int> by name */
    public function attributes(): array
    {
        return $this->attributes;
    }
}
