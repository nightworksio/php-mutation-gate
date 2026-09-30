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
use function var_export;

/**
 * A config a YAML or NEON parser read, made into the tree every format
 * shares. A date the parser read as a date is written `YYYY-MM-DD` again,
 * and one with a time of day is refused. A number JSON cannot hold, such as
 * an unquoted mutant id read as an infinite float, becomes text the validator
 * can name. Any other object is refused: config is data (ADR-0002).
 */
final readonly class Parsed
{
    private const string DAY = 'Y-m-d';

    /** The time of day a date without one is read at. */
    private const string TIME = 'H:i:s.u';

    private const string MIDNIGHT = '00:00:00.000000';

    public static function document(mixed $tree, string $file): Document|CannotJudge
    {
        $plain = self::plain($tree, $file);

        return $plain instanceof CannotJudge ? $plain : Document::ofJson(Json::encode($plain));
    }

    private static function plain(mixed $value, string $file): mixed
    {
        return match (true) {
            is_array($value) => self::each($value, $file),
            $value instanceof DateTimeInterface => self::day($value, $file),
            is_float($value) && ! is_finite($value) => var_export($value, return: true),
            is_object($value) => CannotJudge::because(
                sprintf('%s holds an object, %s, and a config holds data only.', $file, get_debug_type($value)),
            ),
            default => $value,
        };
    }

    /** A date as a config writes it, or why it is not one: a date with a time is not a day. */
    private static function day(DateTimeInterface $date, string $file): string|CannotJudge
    {
        return $date->format(self::TIME) === self::MIDNIGHT
            ? $date->format(self::DAY)
            : CannotJudge::because(sprintf(
                '%s holds a date with a time, %s; a config date is a day, YYYY-MM-DD.',
                $file,
                $date->format(DateTimeInterface::ATOM),
            ));
    }

    /** @param array<mixed> $values */
    private static function each(array $values, string $file): mixed
    {
        $plain = array_map(static fn(mixed $value): mixed => self::plain($value, $file), $values);

        return array_find($plain, static fn(mixed $value): bool => $value instanceof CannotJudge) ?? $plain;
    }
}
