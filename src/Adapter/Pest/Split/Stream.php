<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Forking;

/**
 * The split stream of this process, where it runs one (ADR-0117), as Pest's
 * own events and the zygote reach it: each answered by the stream itself
 * (see StreamRun), and by nothing where the process runs none.
 *
 * @internal
 */
final class Stream
{
    private static ?StreamRun $run = null;

    /** Starts a split stream from the plan this file holds, where PHP can fork. */
    public static function from(string $file): bool
    {
        $plan = Forking::isAvailable() ? StreamPlanFile::read($file) : false;

        if ($plan === false) {
            return false;
        }

        self::$run = StreamRun::of($plan);

        return true;
    }

    /** A test begins: the sites it reaches are its own, and none of them is forked yet. */
    public static function began(string $test): void
    {
        self::$run?->began($test);
    }

    /** The test failed or errored, in this process. */
    public static function failed(): void
    {
        self::$run?->fail();
    }

    /** The stream starts or stops booting the application it hands each test's own process. */
    public static function booting(bool $booting): void
    {
        self::$run?->booting($booting);
    }

    /**
     * Forks the current test from the booted application: true in the
     * test's own process, false in the stream's; none where no stream can
     * fork here.
     */
    public static function forkedTest(): bool|NotGiven
    {
        return self::$run instanceof StreamRun ? self::$run->forkedTest() : NotGiven::value();
    }

    /** The test raised an issue: it was risky, or triggered a deprecation, a notice or a warning. */
    public static function troubled(): void
    {
        self::$run?->trouble();
    }

    /** The test runs in a process of its own, where no fork reaches it. */
    public static function isolated(): void
    {
        self::$run?->isolated();
    }

    /** The test ended. */
    public static function ended(): void
    {
        self::$run?->ended();
    }

    /** The run ended: the parent waits for every child, each held to its limit. */
    public static function finished(): void
    {
        self::$run?->finished();
    }
}
