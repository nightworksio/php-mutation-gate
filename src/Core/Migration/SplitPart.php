<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use function sprintf;

/** A part of a split object, and the key it moves to: `seconds` to `timeouts.seconds`. */
final readonly class SplitPart
{
    private function __construct(private string $part, private KeyPath $to)
    {
    }

    public static function of(string $part, string $to): self
    {
        return new self($part, KeyPath::of($to));
    }

    /** Where it is written, under the object split from this path. */
    public function under(KeyPath $from): KeyPath
    {
        return KeyPath::of(sprintf('%s.%s', $from->value(), $this->part));
    }

    public function to(): KeyPath
    {
        return $this->to;
    }
}
