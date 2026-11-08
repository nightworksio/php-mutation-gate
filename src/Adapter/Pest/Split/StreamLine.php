<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * One line a split stream writes to its results (ADR-0117): how the parent
 * ended a test, how a child ended its mutant's test, a child it stopped at
 * its limit, or a test it forks nothing in. Each is a JSON object on a line
 * of its own, appended under a lock.
 *
 * @internal
 */
final readonly class StreamLine
{
    /** The field that says what a line is. */
    public const string KIND = 'k';

    public const string PARENT = 'parent';

    public const string CHILD = 'child';

    public const string ENDED = 'ended';

    public const string TIMEOUT = 'timeout';

    public const string FALLBACK = 'fallback';

    /** The parent ended a test: passed or not, in this many seconds. */
    public static function parent(string $test, bool $passed, float $seconds): string
    {
        return self::line([self::KIND => self::PARENT, 't' => $test, 'p' => $passed, 's' => $seconds]);
    }

    /** A child ended its mutant's test: failed or not, in this many seconds since the test began. */
    public static function child(string $mutant, string $test, bool $failed, float $seconds): string
    {
        return self::line([self::KIND => self::CHILD, 'm' => $mutant, 't' => $test, 'f' => $failed, 's' => $seconds]);
    }

    /** A child ended in its mutant's test without ending the test: by an exit with this code, or -1 for a signal. */
    public static function endedChild(string $mutant, string $test, int $code): string
    {
        return self::line([self::KIND => self::ENDED, 'm' => $mutant, 't' => $test, 'c' => $code]);
    }

    /** The parent stopped a child past its limit in its mutant's test. */
    public static function timedOut(string $mutant, string $test): string
    {
        return self::line([self::KIND => self::TIMEOUT, 'm' => $mutant, 't' => $test]);
    }

    /** The stream forks nothing in this test. */
    public static function fallback(string $test): string
    {
        return self::line([self::KIND => self::FALLBACK, 't' => $test]);
    }

    /** @param array<string, string|bool|float|int> $fields */
    private static function line(array $fields): string
    {
        return json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
