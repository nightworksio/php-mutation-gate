<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;

use const INF;

use NightWorksIO\MutationGate\Core\Config\FirstFork;

/**
 * The covering test a first-pass stream forks a mutant at (ADR-0187): the
 * ledger's likely killer, where it names one; under `splitStream.firstFork`
 * `cheapest`, of a mutant it names none of, the covering test of the shard's
 * part the map timed fastest, the first of those as fast, one the map did
 * not time after every one it did; otherwise none, so the stream forks it at
 * every covering test of the part.
 *
 * @internal
 */
final readonly class FirstForks
{
    /** @param array<string, float> $times each test's seconds, by its id */
    private function __construct(private FirstFork $fork, private array $times)
    {
    }

    /**
     * Where `splitStream.firstFork` says, by these times.
     *
     * @param array<string, float> $times each test's seconds, by its id
     */
    public static function of(FirstFork $fork, array $times): self
    {
        return new self($fork, $times);
    }

    /** At the ledger's likely killer alone, or at every covering test where it names none. */
    public static function atKillers(): self
    {
        return new self(FirstFork::Every, []);
    }

    /**
     * The test a first pass forks a mutant at, whose likely killer is this,
     * '' where the ledger names none, among those of its covering tests the
     * shard's part holds; '' where it forks it at every one.
     *
     * @param list<string> $held
     */
    public function at(string $killer, array $held): string
    {
        if ($killer !== '' || $this->fork === FirstFork::Every) {
            return $killer;
        }

        $cheapest = '';
        $least = INF;

        foreach ($held as $test) {
            $time = array_key_exists($test, $this->times) ? $this->times[$test] : INF;

            if ($cheapest === '' || $time < $least) {
                $cheapest = $test;
                $least = $time;
            }
        }

        return $cheapest;
    }
}
