<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function clearstatcache;
use function fclose;
use function filesize;
use function flock;
use function fopen;
use function is_file;
use function is_int;

use NightWorksIO\MutationGate\Adapter\Process\Running;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * A process a test's program leaves behind, whose parent ends before it: it
 * holds a lock on `alive` in its directory for as long as it lives, says
 * `ready` into `ready` once it holds it, and touches `outlived` if it lives
 * out its twenty seconds. A test reads whether it is gone by whether the lock
 * can be taken, without waiting on a process frozen while it holds it.
 */
final readonly class Orphan
{
    /** What PHP runs as the orphan. */
    public const string CODE = 'flock($lock = fopen("alive", "c"), LOCK_EX); echo "ready\n"; sleep(20); touch("outlived");';

    /** What a shell runs to leave the orphan behind, its PHP its first word and the orphan's code its second. */
    public const string LEFT = '("$0" -r "$1" > ready 2>&1 &); until [ -s ready ]; do sleep 0.05; done';

    /** How many times a test looks whether the orphan is gone, a fiftieth of a second apart. */
    private const int LOOKS = 250;

    private const float LOOK = 0.02;

    /** Whether the orphan in this directory said it is ready. */
    public static function isReady(string $directory): bool
    {
        $ready = sprintf('%s/ready', $directory);
        clearstatcache();
        $size = is_file($ready) ? filesize($ready) : false;

        return is_int($size) && $size > 0;
    }

    /** Whether the orphan in this directory is gone within five seconds, without living out its sleep. */
    public static function isGone(string $directory): bool
    {
        $alive = fopen(sprintf('%s/alive', $directory), 'c');

        if ($alive === false) {
            return false;
        }

        for ($look = 0; $look < self::LOOKS; $look++) {
            if (flock($alive, LOCK_EX | LOCK_NB)) {
                fclose($alive);

                return ! is_file(sprintf('%s/outlived', $directory));
            }

            Running::awaitAny(Seconds::of(self::LOOK));
        }

        fclose($alive);

        return false;
    }
}
