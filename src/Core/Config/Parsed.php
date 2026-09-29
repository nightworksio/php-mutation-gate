<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_find;
use function array_map;

use DateTimeInterface;

use function get_debug_type;
use function is_array;
use function is_finite;
use function is_float;
use function is_object;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Definition\Json;

use function sprintf;

/**
 * A config a YAML or NEON parser read, made into the tree every format
 * shares. A date the parser read as a date is written `YYYY-MM-DD` again,
 * and a number JSON cannot hold, such as an unquoted mutant id read as an
 * infinite float, becomes text the validator can name. Any other object is
 * refused: config is data (ADR-0002).
 */
final readonly class Parsed
{
    private const string DAY = 'Y-m-d';

    public static function document(mixed $tree, string $file): Document|CannotJudge
    {
        $plain = self::plain($tree, $file);

        return $plain instanceof CannotJudge ? $plain : Document::ofJson(Json::encode($plain));
    }

    private static function plain(mixed $value, string $file): mixed
    {
        return match (true) {
            is_array($value) => self::each($value, $file),
            $value instanceof DateTimeInterface => $value->format(self::DAY),
            is_float($value) && ! is_finite($value) => sprintf('%s', $value),
            is_object($value) => CannotJudge::because(
                sprintf('%s holds an object, %s, and a config holds data only.', $file, get_debug_type($value)),
            ),
            default => $value,
        };
    }

    /** @param array<mixed> $values */
    private static function each(array $values, string $file): mixed
    {
        $plain = array_map(static fn(mixed $value): mixed => self::plain($value, $file), $values);

        return array_find($plain, static fn(mixed $value): bool => $value instanceof CannotJudge) ?? $plain;
    }
}
