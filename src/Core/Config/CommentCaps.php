<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Comment\Cap;

/**
 * How many of each list the comment shows, and how many characters of one
 * text, as `comment.<cap>.most` sets them (ADR-0102); a cap no layer sets
 * keeps its standard.
 */
final readonly class CommentCaps
{
    /** @param array<string, int> $most by the cap's name, those set */
    private function __construct(private array $most)
    {
    }

    /** No cap set. */
    public static function none(): self
    {
        return new self([]);
    }

    /** One cap set to this. */
    public static function capping(Cap $cap, int $most): self
    {
        return new self([$cap->value => $most]);
    }

    /** These, with what a later layer sets laid over them, cap by cap. */
    public function over(self $later): self
    {
        return new self([...$this->most, ...$later->most]);
    }

    /** How many this cap lets the comment show: as set, or its standard. */
    public function most(Cap $cap): int
    {
        $set = $this->set($cap);

        return $set instanceof Absent ? $cap->standard() : $set;
    }

    /** What a layer sets of this cap; nothing where none does. */
    public function set(Cap $cap): int|Absent
    {
        return array_key_exists($cap->value, $this->most) ? $this->most[$cap->value] : Absent::setting();
    }
}
