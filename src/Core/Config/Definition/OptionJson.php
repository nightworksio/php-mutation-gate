<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use BackedEnum;

use function is_object;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Table;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * A built-in adapter's option as the definition read it, written back as
 * JSON: a path as its path from the project, a list item by item.
 */
final readonly class OptionJson
{
    public static function of(object|string|int|float|bool $value): Json|string|int|float|bool|Absent
    {
        if ($value instanceof Absent || ! $value instanceof Listed) {
            return $value instanceof Absent ? $value : self::item($value);
        }

        $items = [];

        foreach ($value as $item) {
            $items[] = self::item($item);
        }

        return Json::items(...$items);
    }

    private static function item(object|string|int|float|bool $value): Json|string|int|float|bool
    {
        return match (true) {
            $value instanceof Path => $value->value(),
            $value instanceof Table => $value->written(),
            $value instanceof BackedEnum => $value->value,
            $value instanceof Json, ! is_object($value) => $value,
            default => throw MisreadSetting::as('an option', 'a path, a list, or a value JSON writes'),
        };
    }
}
