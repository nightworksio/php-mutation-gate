<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function sprintf;

/**
 * A value of a setting that loosens what a run judges, in force: the key, the value as a config writes it, and
 * what it lets count or carry (ADR-0142).
 */
final readonly class LooseValue
{
    private const string NOTED = '%s is %s, a loose value: %s';

    private function __construct(private string $key, private string $value, private string $lets)
    {
    }

    public static function of(string $key, string $value, string $lets): self
    {
        return new self($key, $value, $lets);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function value(): string
    {
        return $this->value;
    }

    /** What it lets count or carry. */
    public function lets(): string
    {
        return $this->lets;
    }

    /** The note a run makes of it. */
    public function noted(): string
    {
        return sprintf(self::NOTED, $this->key, $this->value, $this->lets);
    }
}
