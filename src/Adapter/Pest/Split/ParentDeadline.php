<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use function max;
use function microtime;

/**
 * The time by which a split stream's parent must end the test it runs
 * (ADR-0182): the limit a child of the test is held to, of the map's time
 * of the test times how much slower than the map the stream has run its
 * tests so far, or `timeouts.most` where the map timed none, from the test's
 * start, moved on by each wait for a child's place. The parent keeps it in
 * its stream's mark, where Pest's own process reads it (see StreamWatch).
 *
 * @internal
 */
final class ParentDeadline
{
    private const string TEST = 't';

    private const string BY = 'd';

    /** How far each test the map timed moves the stream's slowdown toward its own. */
    private const float WEIGHT = 0.2;

    /** The least time the map gave a test whose own time moves the slowdown, as shorter ones are mostly start-up. */
    private const float MEASURABLE = 0.05;

    /** How much slower than the map the stream runs its tests, never less than as fast. */
    private float $slowdown = 1.0;

    private float $by = 0.0;

    private string $test = '';

    /** @param array<string, float> $times each test's own seconds, as the map timed it */
    private function __construct(
        private readonly string $mark,
        private readonly ChildLimit $limit,
        private readonly array $times,
    ) {
    }

    /** The deadline of the parent of the stream this plan runs, kept in this mark; none kept where no mark is given. */
    public static function of(StreamPlan $plan, string $mark): self
    {
        return new self($plan->deadline ? $mark : '', $plan->limit, $plan->times);
    }

    /** The test that passed the deadline kept in this mark by this time; false where none did, or none is kept. */
    public static function passedIn(string $mark, float $now): string|false
    {
        $read = is_file($mark) ? json_decode((string) file_get_contents($mark), associative: true) : null;
        $by = is_array($read) && array_key_exists(self::BY, $read) ? $read[self::BY] : null;
        $test = is_array($read) && array_key_exists(self::TEST, $read) ? $read[self::TEST] : null;

        return is_string($test) && (is_float($by) || is_int($by)) && $now > $by ? $test : false;
    }

    /** The parent began this test: its deadline is the test's limit from now. */
    public function began(string $test): void
    {
        $this->test = $test;
        $this->by = microtime(as_float: true) + (array_key_exists($test, $this->times)
            ? $this->limit->of($this->times[$test] * $this->slowdown)
            : $this->limit->longest());
        $this->kept();
    }

    /** The parent waited this many seconds for a child's place: the test's deadline moves on by as long. */
    public function waited(float $seconds): void
    {
        if ($this->test === '' || $seconds <= 0.0) {
            return;
        }

        $this->by += $seconds;
        $this->kept();
    }

    /**
     * The parent ended its test, taking this many seconds of its own: it runs
     * none past a deadline, and a test the map timed moves the slowdown.
     */
    public function ended(float $own): void
    {
        $timed = array_key_exists($this->test, $this->times) ? $this->times[$this->test] : 0.0;
        $this->slowdown = $timed >= self::MEASURABLE
            ? max(1.0, $this->slowdown + self::WEIGHT * ($own / $timed - $this->slowdown))
            : $this->slowdown;
        $this->test = '';

        if ($this->mark !== '' && is_file($this->mark)) {
            file_put_contents($this->mark, '');
        }
    }

    private function kept(): void
    {
        if ($this->mark === '') {
            return;
        }

        $kept = [self::TEST => $this->test, self::BY => $this->by];
        file_put_contents($this->mark, (string) json_encode($kept, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
