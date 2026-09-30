<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Order;

/** A named function, and the tests that killed its mutants first, most kills first. */
final readonly class RankedFunction
{
    private function __construct(private Enclosing $function, private Ranking $ranking)
    {
    }

    public static function of(Enclosing $function, Ranking $ranking): self
    {
        return new self($function, $ranking);
    }

    public function function(): Enclosing
    {
        return $this->function;
    }

    public function ranking(): Ranking
    {
        return $this->ranking;
    }
}
