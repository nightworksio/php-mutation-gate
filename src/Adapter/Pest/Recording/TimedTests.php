<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function array_filter;
use function array_key_exists;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_array;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function json_validate;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Event\Facade;
use PHPUnit\Event\Telemetry\HRTime;
use PHPUnit\Event\UnknownSubscriberTypeException;

use function sprintf;

/**
 * Every test a coverage run finished, by its id as the map names it, with
 * how long it took from its preparation to its end, a line each appended to
 * the file `MUTATION_GATE_TIMED_TESTS` names, by every process of the run.
 * The map PHPUnit writes leaves out a test that ran no line it measures, so
 * the gate adds these to it: the map then knows every test that ran.
 */
final class TimedTests
{
    /** @var array<string, HRTime> when each test running was prepared, by its id */
    private array $started = [];

    private function __construct(private readonly string $file)
    {
    }

    /** Timing where the adapter named a file and PHPUnit still takes subscribers. */
    public static function fromEnvironment(): self|Off
    {
        return self::listening(getenv(GateVariable::TimedTests->value), Facade::instance());
    }

    public static function listening(string|false $file, Facade $events): self|Off
    {
        if (! is_string($file) || $file === '') {
            return Off::TimingTests;
        }

        $timed = new self($file);

        try {
            $events->registerSubscribers(new OnTimedPrepared($timed), new OnTimedFinished($timed));
        } catch (EventFacadeIsSealedException|UnknownSubscriberTypeException) {
            return Off::TimingTests;
        }

        return $timed;
    }

    /**
     * Every test a file records as timed, each with its seconds; none where
     * it is not there, and no line it cannot read.
     *
     * @return list<TimedTest>
     */
    public static function in(string $file): array
    {
        $timed = [];

        foreach (explode("\n", is_file($file) ? (string) file_get_contents($file) : '') as $line) {
            $read = self::timedIn($line);
            $timed = $read instanceof TimedTest ? [...$timed, $read] : $timed;
        }

        return $timed;
    }

    /** A map knowing every test a file times that it does not time itself, with the file's seconds for it. */
    public static function onto(CoverageMap $map, string $file): CoverageMap
    {
        $unknown = array_filter(
            self::in($file),
            static fn(TimedTest $timed): bool => $map->durationOf($timed->test()) instanceof Unmeasured,
        );

        return $map->timedEach(...$unknown);
    }

    /** Notes when a test was prepared. */
    public function prepared(string $test, HRTime $at): void
    {
        $this->started[$test] = $at;
    }

    /** Writes a test that finished, with how long it took since it was prepared; nothing for one never prepared. */
    public function finished(string $test, HRTime $at): void
    {
        if (array_key_exists($test, $this->started)) {
            $seconds = $at->duration($this->started[$test])->asFloat();
            file_put_contents($this->file, sprintf("%s\n", json_encode([$test, $seconds])), FILE_APPEND | LOCK_EX);
            unset($this->started[$test]);
        }
    }

    /** The test a line names, with its seconds; none where the line holds no such pair. */
    private static function timedIn(string $line): TimedTest|NotGiven
    {
        $read = json_validate($line) ? json_decode($line, associative: true) : null;
        $test = is_array($read) && array_key_exists(0, $read) ? $read[0] : null;
        $seconds = is_array($read) && array_key_exists(1, $read) ? $read[1] : null;

        return is_string($test) && (is_float($seconds) || is_int($seconds))
            ? TimedTest::of($test, $seconds)
            : NotGiven::value();
    }
}
