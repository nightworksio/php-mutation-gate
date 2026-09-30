<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_any;
use function array_map;

use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * The presets a config names: one name, or a list of them applied in order.
 *
 * @implements Shape<Listed<string>>
 */
final readonly class Presets implements Shape
{
    public static function named(): self
    {
        return new self();
    }

    public function read(Node $at): Reading
    {
        $items = match ($at->kind()) {
            Kind::Text => [$at],
            Kind::List, Kind::Empty => $at->items(),
            Kind::Map, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null, Kind::Nothing => [$at->field('')],
        };
        $names = array_map(static fn(Node $item): string => $item->kind() === Kind::Text ? $item->text() : '', $items);

        return array_any($names, static fn(string $name): bool => $name === '')
            ? Reading::refused($at->mismatch($this->expected()))
            : Reading::of(Listed::of(...$names));
    }

    public function expected(): string
    {
        return 'a preset name, or a list of them';
    }

    public function schema(): Json
    {
        $name = Json::object(Member::of('type', 'string'))->with(Member::of('minLength', 1));

        return Json::object()
            ->with(Member::of('description', 'Chosen from what composer.json requires when no layer names one.'))
            ->with(
                Member::of(
                    'anyOf',
                    Json::items($name, Json::object(Member::of('type', 'array'))->with(Member::of('items', $name))),
                ),
            );
    }

    public function effects(): array
    {
        return [];
    }
}
