<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

/**
 * An object whose keys belong to something outside the gate, such as a
 * third-party adapter's options or a CI's step, read into its JSON text.
 */
final readonly class OpenObject implements Node
{
    public static function any(): self
    {
        return new self();
    }

    public function read(mixed $value, string $at): Reading
    {
        return Json::isMap($value)
            ? Reading::of(Json::encode(Json::object($value)), Json::object($value))
            : Reading::mismatch($at, $this->expected(), $value);
    }

    public function expected(): string
    {
        return 'an object';
    }

    public function schema(): array
    {
        return ['type' => 'object'];
    }

    public function effects(): array
    {
        return [];
    }
}
