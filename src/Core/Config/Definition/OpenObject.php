<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * An object of any keys, kept as it is written: options a third party's
 * adapter checks itself, or keys the gate passes on to a CI unread.
 *
 * @implements Shape<Json>
 */
final readonly class OpenObject implements Shape
{
    public static function any(): self
    {
        return new self();
    }

    public function read(Node $at): Reading
    {
        return match ($at->kind()) {
            Kind::Map => Reading::of($at->value()),
            Kind::Empty => Reading::of(Json::object()),
            Kind::List, Kind::Text, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null, Kind::Nothing
                => Reading::refused($at->mismatch($this->expected())),
        };
    }

    public function expected(): string
    {
        return 'an object';
    }

    public function schema(): Json
    {
        return Json::object()->with('type', 'object');
    }

    public function effects(): array
    {
        return [];
    }
}
