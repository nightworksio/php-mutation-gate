<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function count;

use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * Every test that covers or killed a mutant of a verdict, or of one mutant
 * explained, each once, in the order the mutants first name them, so a
 * report can list the tests once and point at them by their place
 * (ADR-0014, decision 9).
 */
final readonly class TestTable
{
    /** @param array<string, int> $places each test's place, by its id */
    private function __construct(private TestIds $tests, private array $places)
    {
    }

    public static function of(Verdict $verdict): self
    {
        return self::over($verdict->matrix(), ...$verdict->trees()->mutants());
    }

    /** Every test that covers or killed these mutants, by the matrix, each once in the order they first name them. */
    public static function over(KillMatrix $matrix, JudgedMutant|JudgedKill ...$mutants): self
    {
        $tests = TestIds::none();
        $places = [];

        foreach ($mutants as $judged) {
            foreach ($matrix->coveredBy($judged) as $test) {
                if ($tests->has($test)) {
                    continue;
                }

                $places[$test->value()] = count($places);
                $tests = $tests->with($test);
            }
        }

        return new self($tests, $places);
    }

    /** The tests, in their places. */
    public function tests(): TestIds
    {
        return $this->tests;
    }

    /**
     * The places of these tests, in their order.
     *
     * @return list<int>
     */
    public function placesOf(TestIds $tests): array
    {
        $places = [];

        foreach ($tests as $test) {
            $places[] = $this->placeOf($test);
        }

        return $places;
    }

    private function placeOf(TestId $test): int
    {
        return $this->places[$test->value()];
    }
}
