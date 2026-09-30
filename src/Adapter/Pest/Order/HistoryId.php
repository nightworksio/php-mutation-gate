<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Order;

use function preg_match;
use function sprintf;

/**
 * A test as PHPUnit's test run history keys it, from its id as the coverage
 * map names it, the one place one becomes the other. They differ only for a
 * row of a data set: the map writes `Class::method#name`, and the history
 * `Class::method with data set "name"`, or `with data set #0` for a row PHP
 * keys by a number.
 */
final readonly class HistoryId
{
    /** A test id with a data set's row after the first `#` past its `::`. */
    private const string ROW = '/^(?<test>[^#]*::[^#]*)#(?<row>.*)$/sD';

    /** A row PHP keys by a number. */
    private const string NUMBERED = '/^-?\d+$/D';

    public static function of(string $test): string
    {
        if (preg_match(self::ROW, $test, $found) !== 1) {
            return $test;
        }

        return preg_match(self::NUMBERED, $found['row']) === 1
            ? sprintf('%s with data set #%s', $found['test'], $found['row'])
            : sprintf('%s with data set "%s"', $found['test'], $found['row']);
    }
}
