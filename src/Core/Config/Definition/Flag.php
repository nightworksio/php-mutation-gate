<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_bool;

/** `true` or `false`, and nothing that merely reads as one. */
final readonly class Flag implements Node
{
    public static function boolean(): self
    {
        return new self();
    }

    public function read(mixed $value, string $at): Reading
    {
        return is_bool($value) ? Reading::of($value, $value) : Reading::mismatch($at, $this->expected(), $value);
    }

    public function expected(): string
    {
        return 'true or false';
    }

    public function schema(): array
    {
        return ['type' => 'boolean'];
    }

    public function effects(): array
    {
        return [];
    }
}
