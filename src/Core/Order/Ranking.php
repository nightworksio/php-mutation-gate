<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Order;

use function array_slice;
use function array_splice;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use Traversable;

use function usort;

/**
 * The tests that killed a mutant, or the mutants of a function, most kills
 * first, keeping the five most. Of two tests with as many kills, the one that
 * killed most recently comes first, so a new killer can displace an old one.
 *
 * @implements IteratorAggregate<int, Kills>
 */
final readonly class Ranking implements Countable, IteratorAggregate
{
    /** How many tests a ranking keeps. */
    private const int TOP = 5;

    /** @param list<Kills> $kills most first */
    private function __construct(private array $kills)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These kills, most first, the five most; of two with as many, the one given first. */
    public static function of(Kills ...$kills): self
    {
        $ranked = array_values($kills);
        usort($ranked, static fn(Kills $one, Kills $other): int => $other->count() <=> $one->count());

        return new self(array_slice($ranked, 0, self::TOP));
    }

    /** This ranking, with one more kill by a test, placed ahead of every test with as many. */
    public function killedBy(TestId $test): self
    {
        $others = [];
        $kills = Kills::first($test);

        foreach ($this->kills as $ranked) {
            $same = $ranked->test()->value() === $test->value();
            $kills = $same ? $ranked->andOneMore() : $kills;
            $others = $same ? $others : [...$others, $ranked];
        }

        $at = 0;

        while ($at < count($others) && $others[$at]->count() > $kills->count()) {
            $at++;
        }

        array_splice($others, $at, 0, [$kills]);

        return new self(array_slice($others, 0, self::TOP));
    }

    /** The tests, most kills first. */
    public function tests(): TestIds
    {
        $tests = TestIds::none();

        foreach ($this->kills as $kills) {
            $tests = $tests->with($kills->test());
        }

        return $tests;
    }

    public function count(): int
    {
        return count($this->kills);
    }

    /** @return Traversable<int, Kills> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->kills);
    }
}
