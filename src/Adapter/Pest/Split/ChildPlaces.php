<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function count;
use function getmypid;
use function glob;
use function intdiv;
use function is_array;
use function is_file;
use function max;
use function register_shutdown_function;
use function sprintf;
use function touch;
use function unlink;

/**
 * How many children a split stream holds at once (ADR-0117): a count, or the
 * logical cores shared among the streams of its run still running, at least
 * one, each stream marked running in the run's stream directory from its
 * start to its end (ADR-0173).
 *
 * @internal
 */
final readonly class ChildPlaces
{
    /** A running stream's mark, by its process id, in the run's stream directory. */
    private const string LIVE = '%s/live-%d';

    /** Every running stream's mark, as `glob()` matches them. */
    private const string EVERY_LIVE = '%s/live-*';

    private function __construct(private int $count, private int $cores, private string $directory)
    {
    }

    /** This many children at once, at least one, the stream marked running in this directory where one is given. */
    public static function fixed(int $count, string $directory = ''): self
    {
        return new self(max(1, $count), 0, $directory);
    }

    /** These logical cores shared among the streams marked running in this directory. */
    public static function sharing(int $cores, string $directory): self
    {
        return new self(0, max(1, $cores), $directory);
    }

    /** Marks the stream of this process running until it ends; nothing where no directory is given. */
    public function joined(): void
    {
        $pid = getmypid();

        if ($this->directory === '' || $pid === false) {
            return;
        }

        $mark = self::markOf($this->directory, $pid);
        touch($mark);
        register_shutdown_function(static function () use ($mark, $pid): void {
            if (getmypid() === $pid && is_file($mark)) {
                unlink($mark);
            }
        });
    }

    /** The mark of the stream of this process, where it is marked running; '' where it is not. */
    public function mark(): string
    {
        $pid = getmypid();

        return $this->directory === '' || $pid === false ? '' : self::markOf($this->directory, $pid);
    }

    /** The mark of the stream of this process id in this directory. */
    public static function markOf(string $directory, int $pid): string
    {
        return sprintf(self::LIVE, $directory, $pid);
    }

    /** The most children the stream may ever hold at once: the count, or every core. */
    public function most(): int
    {
        return $this->count > 0 ? $this->count : $this->cores;
    }

    /** How many children the stream may hold now: the count, or its share of the cores among the running streams. */
    public function now(): int
    {
        if ($this->count > 0) {
            return $this->count;
        }

        return max(1, intdiv($this->cores, max(1, self::runningIn($this->directory))));
    }

    /** How many streams are marked running in this directory. */
    public static function runningIn(string $directory): int
    {
        $live = glob(sprintf(self::EVERY_LIVE, $directory));

        return is_array($live) ? count($live) : 0;
    }
}
