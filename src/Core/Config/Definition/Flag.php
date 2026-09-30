<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * `true` or `false`.
 *
 * @implements Shape<bool>
 */
final readonly class Flag implements Shape
{
    public static function boolean(): self
    {
        return new self();
    }

    public function read(Node $at): Reading
    {
        return $at->kind() === Kind::Boolean
            ? Reading::of($at->boolean())
            : Reading::refused($at->mismatch($this->expected()));
    }

    public function expected(): string
    {
        return 'true or false';
    }

    public function schema(): Json
    {
        return Json::object()->with('type', 'boolean');
    }

    public function effects(): array
    {
        return [];
    }
}
