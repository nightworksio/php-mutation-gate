<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Php\Tokens;

/**
 * Where a test a file declares stands, by the method its coverage id names:
 * from the end of the statement before it to the end of its own, its
 * comments, attributes and modifiers among it.
 *
 * @internal
 */
final readonly class Declared
{
    private function __construct(private string $method, private int $from, private int $to)
    {
    }

    /**
     * A test declared from the first token of a statement to the token at
     * another index, from the end of the token before that statement.
     */
    public static function between(string $method, Tokens $tokens, int $first, int $last): self
    {
        $from = $first === 0 ? 0 : $tokens->offset($first - 1) + Bytes::length($tokens->text($first - 1));

        return new self($method, $from, $tokens->offset($last) + Bytes::length($tokens->text($last)));
    }

    public function method(): string
    {
        return $this->method;
    }

    /** The byte it begins at, from 0. */
    public function from(): int
    {
        return $this->from;
    }

    /** The byte past its last. */
    public function to(): int
    {
        return $this->to;
    }
}
