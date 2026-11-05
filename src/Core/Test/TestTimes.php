<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_key_exists;

use Generator;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * Tests' own times, as a coverage map measured them, each test once: the
 * last time given for it.
 *
 * @implements IteratorAggregate<int, TestTime>
 */
final readonly class TestTimes implements IteratorAggregate
{
    /** @param array<string, float> $seconds each test's own seconds, by its id */
    private function __construct(private array $seconds)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(TestTime ...$times): self
    {
        $seconds = [];

        foreach ($times as $time) {
            $seconds[$time->test()->value()] = $time->seconds()->seconds();
        }

        return new self($seconds);
    }

    public function isEmpty(): bool
    {
        return $this->seconds === [];
    }

    /** A test's own time; none where it was not measured. */
    public function secondsOf(TestId $test): Seconds|NotGiven
    {
        return array_key_exists($test->value(), $this->seconds)
            ? Seconds::of($this->seconds[$test->value()])
            : NotGiven::value();
    }

    /** @return Generator<int, TestTime> */
    public function getIterator(): Generator
    {
        foreach ($this->seconds as $test => $seconds) {
            yield TestTime::of(TestId::of(sprintf('%s', $test)), Seconds::of($seconds));
        }
    }
}
