<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_string;

use NightWorksIO\MutationGate\Core\File\Path;

/** A path, relative to the config file, or to the working directory when there is none. */
final readonly class Location implements Node
{
    public static function path(): self
    {
        return new self();
    }

    public function read(mixed $value, string $at): Reading
    {
        return is_string($value) && $value !== ''
            ? Reading::of(Path::of($value), Path::of($value)->value())
            : Reading::mismatch($at, $this->expected(), $value);
    }

    public function expected(): string
    {
        return 'a path';
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
