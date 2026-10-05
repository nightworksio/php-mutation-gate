<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Closure;

use function deflate_add;
use function deflate_init;
use function ini_get;
use function ini_set;
use function intdiv;

use LogicException;

use function memory_get_usage;
use function min;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;

use function sprintf;
use function str_repeat;

/** A small gzip stream that inflates to far more than it is, made a mebibyte at a time so it is never held inflated. */
final class GzipBomb
{
    private const int STEP = 1_048_576;

    /** How many mebibytes above its use a test's process may use while it reads a bomb. */
    private const int HEADROOM = 256;

    /** A stream that inflates to this many zero bytes. */
    public static function of(int $bytes): string
    {
        $deflating = deflate_init(ZLIB_ENCODING_GZIP);
        $stream = '';

        for ($left = $bytes; $left > 0 && $deflating !== false; $left -= self::STEP) {
            $stream .= (string) deflate_add($deflating, str_repeat("\0", min($left, self::STEP)), ZLIB_NO_FLUSH);
        }

        return $deflating === false ? '' : sprintf('%s%s', $stream, deflate_add($deflating, '', ZLIB_FINISH));
    }

    /**
     * A stream that inflates to this many zero bytes, followed by a mebibyte that is never read, so that what it
     * may inflate to in proportion to its bytes is far more than a process's share of memory.
     */
    public static function padded(int $bytes): string
    {
        return sprintf('%s%s', self::of($bytes), str_repeat('x', self::STEP));
    }

    /**
     * A memory_limit a mebibyte-rounded headroom above what this process holds from the system now, so it can always
     * be set: PHP refuses a limit below that real usage, which a long run leaves far above what it uses.
     */
    public static function limitAboveUse(): string
    {
        return sprintf('%dM', intdiv(memory_get_usage(real_usage: true), self::STEP) + self::HEADROOM);
    }

    /**
     * The map a read gives, or why none, while the process's memory_limit is this, its own given back after.
     *
     * @param Closure(): (CoverageMap|CannotJudge) $read
     */
    public static function readUnder(string $memoryLimit, Closure $read): CoverageMap|CannotJudge
    {
        $was = ini_get('memory_limit');

        if (ini_set('memory_limit', $memoryLimit) === false) {
            throw new LogicException(sprintf('memory_limit %s could not be set, so the read would judge under %s.', $memoryLimit, $was));
        }

        $result = $read();
        ini_set('memory_limit', $was);

        return $result;
    }
}
