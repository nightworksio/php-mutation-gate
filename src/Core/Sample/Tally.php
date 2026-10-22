<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

/** How many of a set's counted mutants were killed: one unit's, or a stratum's summed. */
final readonly class Tally
{
    private function __construct(private int $killed, private int $counted)
    {
    }

    public static function of(int $killed, int $counted): self
    {
        return new self($killed, $counted);
    }

    public static function none(): self
    {
        return new self(0, 0);
    }

    public function plus(self $other): self
    {
        return new self($this->killed + $other->killed, $this->counted + $other->counted);
    }

    public function killed(): int
    {
        return $this->killed;
    }

    public function counted(): int
    {
        return $this->counted;
    }
}
