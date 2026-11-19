<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_all;
use function array_key_exists;
use function array_map;
use function array_unique;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sort;
use function sprintf;

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
    private const string COMPLETE = 'complete';

    private const string SUITES = 'suites';

    private const string TESTS = 'tests';

    private const string REPORTED = 'reported';

    /**
     * @param array<string, TestEnded> $tests
     * @param array<string, list<int>> $reported by path from the root
     * @param list<string>             $suites
     */
    private function __construct(
        private bool $complete,
        private array $tests,
        private array $reported,
        private array $suites,
    ) {
    }

    /**
     * @param array<string, TestEnded> $tests
     * @param array<string, list<int>> $reported
     */
    public static function of(bool $complete, array $tests, array $reported): self
    {
        return new self($complete, $tests, $reported, []);
    }

    /** Nothing streamed, which is complete and stands in for no suite. */
    public static function none(): self
    {
        return new self(complete: true, tests: [], reported: [], suites: []);
    }

    /** This and another shard's streams, as one run's. */
    public function and(self $other): self
    {
        $tests = $this->tests;

        foreach ($other->tests as $test => $ended) {
            $tests[$test] = array_key_exists($test, $tests) ? $tests[$test]->and($ended) : $ended;
        }

        $reported = $this->reported;

        foreach ($other->reported as $file => $lines) {
            $reported[$file] = $this->union(array_key_exists($file, $reported) ? $reported[$file] : [], $lines);
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
        return array_all($this->tests, static fn(TestEnded $ended): bool => $ended->standsIn());
    }

    public function count(): int
    {
        return count($this->tests);
    }

    /** @return array<string, float> each streamed test's seconds */
    public function secondsByTest(): array
    {
        return array_map(static fn(TestEnded $ended): float => $ended->seconds, $this->tests);
    }

    /** @return array<string, list<int>> each line pcov reported in each file the tests loaded, by path from the root */
    public function reported(): array
    {
        return $this->reported;
    }

    /** @return list<string> the judging suites the streams ran */
    public function suites(): array
    {
        return $this->suites;
    }

    public function encoded(): string
    {
        return JsonText::compact([
            self::COMPLETE => $this->complete,
            self::SUITES => $this->suites,
            self::TESTS => array_map(static fn(TestEnded $ended): array => $ended->record(), $this->tests),
            self::REPORTED => $this->reported,
        ]);
    }

    /** The suite a file holds; none where it is not one. */
    public static function decoded(string $text): self|NotGiven
    {
        try {
            return self::read(Node::decode($text));
        } catch (NotInShape) {
            return NotGiven::value();
        }
    }

    /** @throws NotInShape */
    private static function read(Node $file): self
    {
        $tests = [];

        foreach ($file->field(self::TESTS)->entries() as $test => $ended) {
            $tests[sprintf('%s', $test)] = TestEnded::read($ended);
        }

        $reported = [];

        foreach ($file->field(self::REPORTED)->entries() as $path => $lines) {
            $reported[sprintf('%s', $path)] = $lines->integers();
        }

        $suites = [];

        foreach ($file->field(self::SUITES)->items() as $suite) {
            $suites[] = $suite->text();
        }

        return new self($file->field(self::COMPLETE)->boolean(), $tests, $reported, $suites);
    }

    /**
     * @param  list<int> $one
     * @param  list<int> $other
     * @return list<int>
     */
    private function union(array $one, array $other): array
    {
        $lines = array_values(array_unique([...$one, ...$other]));
        sort($lines);

        return $lines;
    }
}
