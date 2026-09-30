<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use function count;
use function in_array;

use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use Traversable;

/**
 * A small set of whole tests that keeps every kill a full kill matrix
 * records, not the smallest, and every test outside it, which can all go
 * together without losing one. Tests are kept greedily: first those that
 * must stay, then each time the test that keeps the most kills not yet kept
 * per second of its own time, ties by name. A test no coverage run timed is
 * taken to run as long as the slowest one that was (ADR-0014, decision 8).
 */
final readonly class Redundancy
{
    /**
     * @param list<KillingTest>   $kept      in the order they were kept
     * @param list<RemovableTest> $removable in the order the mutants first name them
     */
    private function __construct(private array $kept, private array $removable)
    {
    }

    /** The kept set and the removable tests of a verdict whose kill matrix is full. */
    public static function of(Verdict $verdict): self
    {
        $tests = KillingTests::of($verdict);
        $mustStay = MustStay::of($verdict);
        $kept = [];
        $rest = [];

        foreach ($tests as $test) {
            if ($mustStay->has($test->test())) {
                $kept[] = $test;

                continue;
            }

            $rest[] = $test;
        }

        $kept = self::greedy($kept, $rest, $tests->slowest());

        return new self($kept, self::removableFrom($kept, $rest));
    }

    /** @return Traversable<int, TestName|TestId> the kept tests, in the order they were kept */
    public function kept(): Traversable
    {
        foreach ($this->kept as $test) {
            yield $test->test();
        }
    }

    /** @return Traversable<int, RemovableTest> */
    public function removable(): Traversable
    {
        yield from $this->removable;
    }

    /**
     * The kept tests: these, then the best of the rest until every kill is kept.
     *
     * @param  list<KillingTest> $kept
     * @param  list<KillingTest> $rest
     * @return list<KillingTest>
     */
    private static function greedy(array $kept, array $rest, Seconds $untimed): array
    {
        $killed = self::killsOf($kept);
        $next = self::best($rest, $killed, $untimed);

        while ($next instanceof KillingTest) {
            $kept[] = $next;
            $killed = $killed->and($next->kills());
            $next = self::best($rest, $killed, $untimed);
        }

        return $kept;
    }

    /**
     * The test not yet kept that keeps the most kills not yet kept per second; none where none keeps any.
     *
     * @param list<KillingTest> $rest
     */
    private static function best(array $rest, MutantIds $killed, Seconds $untimed): KillingTest|false
    {
        $best = false;

        foreach ($rest as $test) {
            $better = count($test->kills()->without($killed)) > 0
                && ($best === false || self::isBetter($test, $best, $killed, $untimed));
            $best = $better ? $test : $best;
        }

        return $best;
    }

    /** Whether one test keeps more new kills per second than another, the faster where equal, then by name. */
    private static function isBetter(KillingTest $one, KillingTest $other, MutantIds $killed, Seconds $untimed): bool
    {
        $seconds = $one->secondsOr($untimed)->seconds();
        $otherSeconds = $other->secondsOr($untimed)->seconds();
        $ours = count($one->kills()->without($killed)) * $otherSeconds;
        $theirs = count($other->kills()->without($killed)) * $seconds;

        return match (true) {
            $ours !== $theirs => $ours > $theirs,
            $seconds !== $otherSeconds => $seconds < $otherSeconds,
            default => $one->test()->value() < $other->test()->value(),
        };
    }

    /**
     * Every test not kept, each kill it makes with the first kept test that makes it too.
     *
     * @param  list<KillingTest> $kept
     * @param  list<KillingTest> $rest
     * @return list<RemovableTest>
     */
    private static function removableFrom(array $kept, array $rest): array
    {
        $removable = [];

        foreach ($rest as $test) {
            if (in_array($test, $kept, strict: true)) {
                continue;
            }

            $removable[] = RemovableTest::of(
                $test->test(),
                $test->seconds(),
                ...self::keptKills($test, $kept),
            );
        }

        return $removable;
    }

    /**
     * @param  list<KillingTest> $kept
     * @return list<KeptKill>
     */
    private static function keptKills(KillingTest $test, array $kept): array
    {
        $kills = [];

        foreach ($test->kills() as $mutant) {
            foreach ($kept as $keeper) {
                if ($keeper->kills()->has($mutant)) {
                    $kills[] = KeptKill::of($mutant, $keeper->test());

                    break;
                }
            }
        }

        return $kills;
    }

    /** @param list<KillingTest> $tests */
    private static function killsOf(array $tests): MutantIds
    {
        $kills = MutantIds::none();

        foreach ($tests as $test) {
            $kills = $kills->and($test->kills());
        }

        return $kills;
    }
}
