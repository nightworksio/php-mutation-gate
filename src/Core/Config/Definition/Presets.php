<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_all;
use function array_is_list;
use function is_array;
use function is_string;

/** One preset's name, or a list of them applied in order (ADR-0008). */
final readonly class Presets implements Node
{
    public static function named(): self
    {
        return new self();
    }

    public function read(mixed $value, string $at): Reading
    {
        $names = is_string($value) ? [$value] : $value;

        return is_array($names) && array_is_list($names) && array_all($names, self::isName(...))
            ? Reading::of($names, $value)
            : Reading::mismatch($at, $this->expected(), $value);
    }

    public function expected(): string
    {
        return 'a preset name, or a list of them';
    }

    public function schema(): array
    {
        $name = ['type' => 'string', 'minLength' => 1];

        return ['anyOf' => [$name, ['type' => 'array', 'items' => $name]]];
    }

    public function effects(): array
    {
        return [];
    }

    private static function isName(mixed $name): bool
    {
        return is_string($name) && $name !== '';
    }
}
