<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_any;
use function array_key_exists;
use function getmypid;
use function glob;
use function is_file;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function register_shutdown_function;

use RuntimeException;
use SplFileObject;

use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function unlink;

/**
 * The fixture projects the runner contract's tests run and write into, each
 * copied once by a process beside the original, at the same depth so every
 * relative path its Composer manifest names still holds, and removed as the
 * process ends. Tests that run side by side, in parallel workers or in a
 * shard's mutant runs, so never share one. Each copy is held by a lock its
 * process keeps; a copy whose lock no process holds, as one left by a
 * process that was killed, is removed by the next process that copies that
 * fixture.
 */
final class OwnFixtures
{
    /** The fixtures copied, each a directory relative to the repository's root. */
    public const array COPIED = [
        'tests/Contract/Runner/fixture',
        'tests/Contract/Runner/infection-fixture',
        'tests/Contract/Runner/phpunit-fixture',
        'tests/Contract/Runner/phpspec-fixture',
        'tests/Contract/Runner/codeception-fixture',
        'tests/Contract/Runner/testo-fixture',
    ];

    /** The copies of every fixture, as the PHPUnit config leaves them out of its suites. */
    public const string EXCLUDED = 'tests/Contract/Runner/*.own-*';

    /** What a copy's name adds to its original's, before the process that made it. */
    private const string MARK = '.own-';


    /** The lock beside a copy that its process holds while it runs. */
    private const string LOCK = '%s.lock';


    /** @var array<string, string> each copy this process made, by its original */
    private static array $made = [];

    /**
     * The path relative to the root where a test reads and writes this one:
     * in this process's copy where it lies in a copied fixture, as it is
     * otherwise.
     */
    public static function placed(string $path): string
    {
        foreach (self::COPIED as $fixture) {
            if ($path === $fixture || str_starts_with($path, sprintf('%s/', $fixture))) {
                return sprintf('%s%s', self::copyOf($fixture), substr($path, strlen($fixture)));
            }
        }

        return $path;
    }

    /** The original of a path in a copy of a fixture, both under the root; the path itself where it lies in none. */
    public static function originalOf(string $path): string
    {
        return (string) preg_replace(sprintf('~%s\\d+(?=/|$)~', preg_quote(self::MARK, '~')), '', $path, 1);
    }

    /** Whether a path relative to the root lies in a copy of a fixture, this process's or another's. */
    public static function holds(string $path): bool
    {
        return array_any(
            self::COPIED,
            static fn(string $fixture): bool => preg_match(self::copiesOf($fixture), $path) === 1,
        );
    }

    /** The pattern of every path in a copy of a fixture, or of its lock. */
    private static function copiesOf(string $fixture): string
    {
        return sprintf('~^%s%s\\d+(/|\\.lock$|$)~', preg_quote($fixture, '~'), preg_quote(self::MARK, '~'));
    }

    /** This process's copy of a fixture, made where it was not yet. */
    private static function copyOf(string $fixture): string
    {
        if (array_key_exists($fixture, self::$made)) {
            return self::$made[$fixture];
        }

        $copy = sprintf('%s%s%d', $fixture, self::MARK, getmypid());
        $into = sprintf('%s/%s', Tree::root(), $copy);
        self::$made[$fixture] = $copy;
        self::sweptBeside(sprintf('%s/%s', Tree::root(), $fixture));
        $lock = self::locked($into);
        FixtureCopy::copied(sprintf('%s/%s', Tree::root(), $fixture), $into);
        register_shutdown_function(static function () use ($into, $lock): void {
            self::removed($into);
            $lock->flock(LOCK_UN);
        });

        return $copy;
    }

    /** The lock of a copy, taken by this process. */
    private static function locked(string $copy): SplFileObject
    {
        $lock = new SplFileObject(sprintf(self::LOCK, $copy), 'c');

        return $lock->flock(LOCK_EX)
            ? $lock
            : throw new RuntimeException(sprintf('The test cannot lock its copy %s.', $copy));
    }

    /** Every copy of a fixture whose lock no process holds, removed. */
    private static function sweptBeside(string $original): void
    {
        $locks = glob(sprintf(self::LOCK, sprintf('%s%s*', $original, self::MARK)));

        foreach ($locks === false ? [] : $locks as $path) {
            $lock = new SplFileObject($path, 'c');

            if ($lock->flock(LOCK_EX | LOCK_NB)) {
                self::removed(substr($path, 0, -strlen(sprintf(self::LOCK, ''))));
                $lock->flock(LOCK_UN);
            }
        }
    }

    /** A copy and its lock removed. */
    private static function removed(string $copy): void
    {
        FixtureCopy::removed($copy);

        if (is_file(sprintf(self::LOCK, $copy))) {
            unlink(sprintf(self::LOCK, $copy));
        }
    }
}
