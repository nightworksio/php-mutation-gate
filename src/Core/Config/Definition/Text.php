<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_string;

/** A string that is not empty, named for what it holds: `a group name`, `a glob`. */
final readonly class Text implements Node
{
    private function __construct(private string $what)
    {
    }

    public static function of(string $what): self
    {
        return new self($what);
    }

    public function read(mixed $value, string $at): Reading
    {
        return is_string($value) && $value !== ''
            ? Reading::of($value, $value)
            : Reading::mismatch($at, $this->what, $value);
    }

    public function expected(): string
    {
        return $this->what;
    }

    public function schema(): array
    {
        return ['type' => 'string', 'minLength' => 1];
    }

    public function effects(): array
    {
        return [];
    }
}
