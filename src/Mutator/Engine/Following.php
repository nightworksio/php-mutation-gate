<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Where one of the lines of a code after one of its newlines starts, read
 * line by line, so reading the first few costs only those.
 *
 * @internal the engine's own
 */
final readonly class Following
{
    private const int NONE_LEFT = -1;

    private function __construct(private string $code, private int $at)
    {
    }

    /** The first of the lines after the newline at this offset; none where the offset is the code's end. */
    public static function lines(string $code, int $newline): self
    {
        return new self($code, $newline === Bytes::length($code) ? self::NONE_LEFT : $newline + 1);
    }

    /** The line this stands at; none past the last. */
    public function line(): string|NotGiven
    {
        return $this->at === self::NONE_LEFT
            ? NotGiven::value()
            : Bytes::slice($this->code, $this->at, Bytes::lineEndOf($this->code, $this->at) - $this->at);
    }

    /** The line this stands at; an empty one past the last. */
    public function read(): string
    {
        $line = $this->line();

        return $line instanceof NotGiven ? '' : $line;
    }

    /** The line after the one this stands at; none past the last. */
    public function next(): self
    {
        $newline = Bytes::find($this->code, "\n", $this->at);

        return new self($this->code, $newline === false ? self::NONE_LEFT : $newline + 1);
    }
}
