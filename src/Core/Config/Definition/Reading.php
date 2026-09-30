<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Problem;

use function sprintf;

/**
 * One value read from a config: its typed value, the form the effective
 * config shows it in, the part of it that affects results, and every problem
 * found reading it. A value with a problem has no typed value.
 */
final readonly class Reading
{
    /** @param list<Problem> $problems */
    private function __construct(
        private mixed $value,
        private mixed $shown,
        private mixed $results,
        private array $problems,
    ) {
    }

    /** A value read cleanly, and how it is shown. */
    public static function of(mixed $value, mixed $shown): self
    {
        return new self($value, $shown, Absent::setting(), []);
    }

    /** A value read cleanly, with the part of it that affects results. */
    public static function affecting(mixed $value, mixed $shown, mixed $results): self
    {
        return new self($value, $shown, $results, []);
    }

    /** A value nobody wrote and nothing defaults. */
    public static function nothing(): self
    {
        return new self(Absent::setting(), Absent::setting(), Absent::setting(), []);
    }

    /** @param list<Problem> $problems */
    public static function refused(array $problems): self
    {
        return new self(Absent::setting(), Absent::setting(), Absent::setting(), $problems);
    }

    /** A value that is not what its definition asks for. */
    public static function mismatch(string $at, string $expected, mixed $got): self
    {
        return self::refused([Problem::at($at, sprintf('expected %s, got %s', $expected, Got::of($got)))]);
    }

    /**
     * This value, which affects results as it is shown when the setting it belongs to does, unless it already
     * says which part of it does.
     */
    public function under(Effect $effect): self
    {
        return $effect === Effect::AffectsResults && $this->results instanceof Absent
            ? clone($this, ['results' => $this->shown])
            : $this;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function shown(): mixed
    {
        return $this->shown;
    }

    public function results(): mixed
    {
        return $this->results;
    }

    /** @return list<Problem> */
    public function problems(): array
    {
        return $this->problems;
    }
}
