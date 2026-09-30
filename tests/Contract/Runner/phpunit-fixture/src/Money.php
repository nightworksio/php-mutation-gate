<?php

declare(strict_types=1);

namespace Library;

final class Money
{
    private int $counted = 0;

    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    /** Counts a call, which no test reads. */
    public function count(): void
    {
        $this->counted = $this->counted + 1;
    }

    public function drain(int $amount): int
    {
        while ($amount !== 0) {
            $amount--;
        }

        return $amount;
    }
}
