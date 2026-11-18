<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function array_keys;
use function usort;

/**
 * What each test costs a pass of split streams (ADR-0188): its map time
 * once for its parent, and once more for each mutant the pass forks at it;
 * a test the map did not time costs nothing.
 *
 * @internal
 */
final readonly class PartCosts
{
    /** @param array<string, float> $costs each test's cost, by its id */
    private function __construct(private array $costs)
    {
    }

    /**
     * Each test's cost by these map times and these counts of mutants forked at it.
     *
     * @param array<string, float> $times
     * @param array<string, int>   $forks
     */
    public static function of(array $times, array $forks): self
    {
        $costs = [];

        foreach ($times as $test => $time) {
            $costs[$test] = $time * (1 + (array_key_exists($test, $forks) ? $forks[$test] : 0));
        }

        return new self($costs);
    }

    /**
     * How many of these mutants a pass forks at each test: once for each mutant whose covering tests it is among.
     *
     * @param  list<string>                $mutants
     * @param  array<string, list<string>> $covering
     * @return array<string, int>
     */
    public static function forksOf(array $mutants, array $covering): array
    {
        $forks = [];

        foreach ($mutants as $mutant) {
            foreach ($covering[$mutant] as $test) {
                $forks[$test] = (array_key_exists($test, $forks) ? $forks[$test] : 0) + 1;
            }
        }

        return $forks;
    }

    /**
     * Each test's cost, by its id.
     *
     * @return array<string, float>
     */
    public function byTest(): array
    {
        return $this->costs;
    }

    /**
     * The places of these parts, the costliest first, parts of equal cost in their order.
     *
     * @param  list<array{files: list<string>, tests: list<string>}> $parts
     * @return list<int>
     */
    public function costliestFirst(array $parts): array
    {
        $cost = [];

        foreach ($parts as $at => $part) {
            $cost[$at] = 0.0;

            foreach ($part['tests'] as $test) {
                $cost[$at] += array_key_exists($test, $this->costs) ? $this->costs[$test] : 0.0;
            }
        }

        $places = array_keys($parts);
        usort($places, static fn(int $one, int $two): int => [$cost[$two], $one] <=> [$cost[$one], $two]);

        return $places;
    }
}
