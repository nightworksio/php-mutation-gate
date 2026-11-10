<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use function sprintf;

/**
 * One line a split stream writes to its results (ADR-0117): how the parent
 * ended a test, how a child ended its mutant's test, a child it stopped at
 * its limit, or a test it forks nothing in; and in a run planned without a
 * fresh map, a test's lines and the end of the stream. Each is a JSON object
 * on a line of its own, appended under a lock.
 *
 * @internal
 */
final readonly class StreamLine
{
    /** The field that says what a line is. */
    public const string KIND = 'k';

    public const string PARENT = 'parent';

    public const string CHILD = 'child';

    public const string ENDED = 'exited';

    public const string TIMEOUT = 'timeout';

    public const string FALLBACK = 'fallback';

    public const string UNSERVED = 'unserved';

    public const string COVERED = 'covered';

    public const string STATE = 'state';

    public const string FINISHED = 'finished';

    public const string BOOTED = 'booted';

    /** The parent ended a test: passed or not, in this many seconds. */
    public static function parent(string $test, bool $passed, float $seconds): string
    {
        return self::line([self::KIND => self::PARENT, 't' => $test, 'p' => $passed, 's' => $seconds]);
    }

    /**
     * A child ended its mutant's test: failed or not, in this many seconds
     * since the test began, and whether the test touched nondeterminism after
     * the fork, as `outbreak.so` saw it.
     */
    public static function child(string $mutant, string $test, bool $failed, float $seconds, bool $touched = false): string
    {
        return self::line([
            self::KIND => self::CHILD,
            'm' => $mutant,
            't' => $test,
            'f' => $failed,
            's' => $seconds,
            'n' => $touched,
        ]);
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

    /**
     * The digest of the state a forked call left as it returned, in the
     * child or in the parent, by its mutant and test (H5).
     */
    public static function state(string $mutant, string $test, bool $child, string $digest): string
    {
        return self::line([self::KIND => self::STATE, 'm' => $mutant, 't' => $test, 'c' => $child, 'd' => $digest]);
    }

    /** The stream forks nothing in this test. */
    public static function fallback(string $test): string
    {
        return self::line([self::KIND => self::FALLBACK, 't' => $test]);
    }

    /** The mutant's site ran while the stream booted its application once, so no test forked from it reaches it. */
    public static function booted(string $mutant): string
    {
        return self::line([self::KIND => self::BOOTED, 'm' => $mutant]);
    }

    /** The stream's process loaded the mutant's file before its schema was served, so it forks nothing of it. */
    public static function unserved(string $mutant): string
    {
        return self::line([self::KIND => self::UNSERVED, 'm' => $mutant]);
    }

    /**
     * The lines of each source file the parent executed in a test, and how
     * the test ended there, as a run that plans without a fresh map records
     * them (ADR-0135).
     *
     * @param array<string, list<int>> $lines
     */
    public static function covered(string $test, array $lines, TestEnded $ended): string
    {
        return self::line([
            self::KIND => self::COVERED,
            't' => $test,
            'l' => $lines,
            's' => $ended->seconds,
            'p' => $ended->passed,
            'i' => $ended->troubled,
            'o' => $ended->own,
        ]);
    }

    /**
     * The parent ran every test of its stream: each line pcov reported in
     * each file its tests loaded, executed or not, which a coverage report
     * counts as the file's executable lines.
     *
     * @param array<string, list<int>> $reported
     */
    public static function finished(array $reported): string
    {
        return self::line([self::KIND => self::FINISHED, 'l' => $reported]);
    }

    /** @param array<string, string|bool|float|int|array<string, list<int>>> $fields */
    private static function line(array $fields): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        return sprintf("%s\n", json_encode($fields, $flags));
    }
}
