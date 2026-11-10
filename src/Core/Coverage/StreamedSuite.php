<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_unique;
use function array_values;
use function count;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use JsonException;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sort;

/**
 * How the tests ran in the parents of a run's split streams, where the run
 * was planned without a fresh map (ADR-0135): each test passed or not,
 * raised an issue or not, ran in a process of its own or not, and in how
 * many seconds; every line pcov reported in each file of the project the
 * tests loaded, executed or not; the judging suites the streams ran; and
 * whether every stream ran each of its tests. Where it stands in, a job that
 * runs the suite takes these suites from it and runs only the rest.
 *
 * @internal
 */
final readonly class StreamedSuite
{
    /**
     * @param array<string, array{passed: bool, troubled: bool, own: bool, seconds: float}> $tests
     * @param array<string, list<int>>                                                     $reported by path from the root
     * @param list<string>                                                                 $suites
     */
    private function __construct(
        private bool $complete,
        private array $tests,
        private array $reported,
        private array $suites,
    ) {
    }

    /**
     * @param array<string, array{passed: bool, troubled: bool, own: bool, seconds: float}> $tests
     * @param array<string, list<int>>                                                     $reported
     */
    public static function of(bool $complete, array $tests, array $reported): self
    {
        return new self($complete, $tests, $reported, []);
    }

    /** Nothing streamed, which is complete and stands in for no suite. */
    public static function none(): self
    {
        return new self(true, [], [], []);
    }

    /** This and another shard's streams, as one run's. */
    public function and(self $other): self
    {
        $tests = $this->tests;

        foreach ($other->tests as $test => $ended) {
            $tests[$test] = isset($tests[$test]) ? self::both($tests[$test], $ended) : $ended;
        }

        $reported = $this->reported;

        foreach ($other->reported as $file => $lines) {
            $reported[$file] = self::union($reported[$file] ?? [], $lines);
        }

        return new self($this->complete && $other->complete, $tests, $reported, $this->suites);
    }

    /** @param list<string> $suites the judging suites the streams ran */
    public function ofSuites(array $suites): self
    {
        return new self($this->complete, $this->tests, $this->reported, $suites);
    }

    /**
     * Whether a job that runs the suite may take the streamed suites from
     * this: every stream ran each of its tests, at least one test ran, each
     * passed and raised no issue, none ran in a process of its own (whose
     * lines no parent collects), and the suites are named.
     */
    public function standsIn(): bool
    {
        if (! $this->complete || $this->tests === [] || $this->suites === []) {
            return false;
        }

        foreach ($this->tests as $ended) {
            if (! $ended['passed'] || $ended['troubled'] || $ended['own']) {
                return false;
            }
        }

        return true;
    }

    public function count(): int
    {
        return count($this->tests);
    }

    /** @return array<string, float> each streamed test's seconds */
    public function secondsByTest(): array
    {
        $seconds = [];

        foreach ($this->tests as $test => $ended) {
            $seconds[$test] = $ended['seconds'];
        }

        return $seconds;
    }

    /** @return array<string, list<int>> every line pcov reported in each file the tests loaded, by path from the root */
    public function reported(): array
    {
        return $this->reported;
    }

    /** @return list<string> the judging suites the streams ran */
    public function suites(): array
    {
        return $this->suites;
    }

    /** @throws JsonException */
    public function encoded(): string
    {
        return json_encode([
            'complete' => $this->complete,
            'suites' => $this->suites,
            'tests' => $this->tests,
            'reported' => $this->reported,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The suite a file holds; none where it is not one. */
    public static function decoded(string $text): self|NotGiven
    {
        try {
            $value = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return NotGiven::value();
        }

        if (! is_array($value) || ! is_bool($value['complete'] ?? null)) {
            return NotGiven::value();
        }

        $tests = self::testsOf($value['tests'] ?? null);
        $reported = self::linesOf($value['reported'] ?? null);
        $suites = self::namesOf($value['suites'] ?? null);

        return $tests instanceof NotGiven || $reported instanceof NotGiven || $suites instanceof NotGiven
            ? NotGiven::value()
            : new self($value['complete'], $tests, $reported, $suites);
    }

    /**
     * @param  array{passed: bool, troubled: bool, own: bool, seconds: float} $one
     * @param  array{passed: bool, troubled: bool, own: bool, seconds: float} $other
     * @return array{passed: bool, troubled: bool, own: bool, seconds: float}
     */
    private static function both(array $one, array $other): array
    {
        return [
            'passed' => $one['passed'] && $other['passed'],
            'troubled' => $one['troubled'] || $other['troubled'],
            'own' => $one['own'] || $other['own'],
            'seconds' => $one['seconds'] + $other['seconds'],
        ];
    }

    /**
     * @param  list<int> $one
     * @param  list<int> $other
     * @return list<int>
     */
    private static function union(array $one, array $other): array
    {
        $lines = array_values(array_unique([...$one, ...$other]));
        sort($lines);

        return $lines;
    }

    /** @return array<string, array{passed: bool, troubled: bool, own: bool, seconds: float}>|NotGiven */
    private static function testsOf(mixed $value): array|NotGiven
    {
        if (! is_array($value)) {
            return NotGiven::value();
        }

        $tests = [];

        foreach ($value as $test => $ended) {
            if (! is_string($test) || ! is_array($ended)) {
                return NotGiven::value();
            }

            $seconds = $ended['seconds'] ?? null;
            [$passed, $troubled, $own] = [$ended['passed'] ?? null, $ended['troubled'] ?? null, $ended['own'] ?? null];

            if (! is_bool($passed) || ! is_bool($troubled) || ! is_bool($own) || ! (is_float($seconds) || is_int($seconds))) {
                return NotGiven::value();
            }

            $tests[$test] = ['passed' => $passed, 'troubled' => $troubled, 'own' => $own, 'seconds' => (float) $seconds];
        }

        return $tests;
    }

    /** @return array<string, list<int>>|NotGiven */
    private static function linesOf(mixed $value): array|NotGiven
    {
        if (! is_array($value)) {
            return NotGiven::value();
        }

        $files = [];

        foreach ($value as $file => $lines) {
            if (! is_string($file) || ! is_array($lines)) {
                return NotGiven::value();
            }

            $files[$file] = [];

            foreach ($lines as $line) {
                if (! is_int($line)) {
                    return NotGiven::value();
                }

                $files[$file][] = $line;
            }
        }

        return $files;
    }

    /** @return list<string>|NotGiven */
    private static function namesOf(mixed $value): array|NotGiven
    {
        if (! is_array($value)) {
            return NotGiven::value();
        }

        $names = [];

        foreach ($value as $name) {
            if (! is_string($name)) {
                return NotGiven::value();
            }

            $names[] = $name;
        }

        return $names;
    }
}
