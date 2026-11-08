<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Comment\Toggle;

/**
 * What the comment says and the runs it is written on, as `comment.<toggle>`
 * turns them on or off (ADR-0102); each is on where no layer sets it.
 */
final readonly class CommentToggles
{
    /** @param array<string, bool> $set by the toggle's name, those set */
    private function __construct(private array $set)
    {
    }

    /** No toggle set. */
    public static function none(): self
    {
        return new self([]);
    }

    /** One toggle turned on. */
    public static function on(Toggle $toggle): self
    {
        return new self([$toggle->value => true]);
    }

    /** One toggle turned off. */
    public static function off(Toggle $toggle): self
    {
        return new self([$toggle->value => false]);
    }

    /** These, with what a later layer sets laid over them, toggle by toggle. */
    public function over(self $later): self
    {
        return new self([...$this->set, ...$later->set]);
    }

    /** Whether this toggle lets the comment say it, or the run write it. */
    public function allows(Toggle $toggle): bool
    {
        $set = $this->set($toggle);

        return $set instanceof Absent || $set;
    }

    /** What a layer sets of this toggle; nothing where none does. */
    public function set(Toggle $toggle): bool|Absent
    {
        return array_key_exists($toggle->value, $this->set) ? $this->set[$toggle->value] : Absent::setting();
    }
}
