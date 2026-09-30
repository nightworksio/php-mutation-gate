<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\Change\CannotTell;

use function sprintf;

/** A pull request's or merge request's number, as a CI names it: a whole number from 1. */
final readonly class PullRequestNumber
{
    private const int FIRST = 1;

    /** @param positive-int $number */
    private function __construct(private int $number)
    {
    }

    /** A number as a CI's variable or URL spells it, digits alone, or why it is none. */
    public static function parse(string $written): self|CannotTell
    {
        $number = (int) $written;

        return $number >= self::FIRST && sprintf('%d', $number) === $written
            ? new self($number)
            : CannotTell::because(sprintf('"%s" is not the number of a pull request.', $written));
    }

    /** A number as an event payload holds it, or why it is none. */
    public static function of(int $number): self|CannotTell
    {
        return self::parse(sprintf('%d', $number));
    }

    /** @return positive-int */
    public function value(): int
    {
        return $this->number;
    }
}
