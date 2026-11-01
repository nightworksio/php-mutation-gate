<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use function array_any;
use function array_key_exists;
use function array_key_first;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;

use function sprintf;

use Traversable;

/**
 * Every hold written only in the suites `tests.holding` lists (ADR-0005). A
 * holding suite's test is kept in a coverage map only on the lines it runs
 * inside the paths it holds, where it judges as any covering test does, and
 * each hold must run a line of what it holds.
 *
 * @internal
 *
 * @implements IteratorAggregate<int, Addition>
 */
final readonly class Additions implements Countable, IteratorAggregate
{
    /** Why a run cannot judge a hold from the holding suites that runs no line of what it holds. */
    private const string UNRUN = <<<'SAID'
        %1$s in the holding suites runs no line of %2$s, so it judges none of its mutants.
        Add the test that runs it to the group, or remove the hold.
        SAID;

    /** @param list<Addition> $additions */
    private function __construct(private array $additions)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function with(Addition $addition): self
    {
        return new self([...$this->additions, $addition]);
    }

    /** The paths they hold. */
    public function paths(): Paths
    {
        $paths = Paths::none();

        foreach ($this->additions as $addition) {
            $paths = $paths->with($addition->path());
        }

        return $paths;
    }

    /** Whether any of them holds a file. */
    public function holds(Path $file): bool
    {
        return array_any($this->additions, static fn(Addition $addition): bool => $file->within($addition->path()));
    }

    /**
     * Why a map cannot be judged by these: the first of them whose tests run
     * no line of the path it holds there; none where each runs one.
     */
    public function unrunIn(CoverageMap $map): CannotJudge|NotGiven
    {
        $unrun = $this->additions;
        $holding = [];

        foreach ($map->lines() as $line) {
            $file = $line->file()->value();

            if (! array_key_exists($file, $holding)) {
                $holding[$file] = $this->indicesOn($line->file());
            }

            $unrun = $this->unrunAfter($unrun, $holding[$file], $line);
        }

        $first = array_key_first($unrun);

        return $first === null
            ? NotGiven::value()
            : CannotJudge::because(sprintf(self::UNRUN, $unrun[$first]->written(), $unrun[$first]->path()->value()));
    }

    /** Those of them that hold a file. */
    public function on(Path $file): self
    {
        $on = [];

        foreach ($this->indicesOn($file) as $at) {
            $on[] = $this->additions[$at];
        }

        return new self($on);
    }

    public function count(): int
    {
        return count($this->additions);
    }

    /** @return Traversable<int, Addition> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->additions);
    }

    /** Whether one of them holds what this test runs on a line of this file. */
    public function adds(TestId $test, Path $file): bool
    {
        return array_any($this->additions, static fn(Addition $addition): bool => $addition->adds($test, $file));
    }

    /** @return list<int> the place of each of them that holds a file */
    private function indicesOn(Path $file): array
    {
        $at = [];

        foreach ($this->additions as $index => $addition) {
            if ($file->within($addition->path())) {
                $at[] = $index;
            }
        }

        return $at;
    }

    /**
     * @param  array<int, Addition> $unrun   the holds no line has been found run for yet, by their place
     * @param  list<int>            $holding the places of the holds that hold the line's file
     * @return array<int, Addition> those of them this line does not run either
     */
    private function unrunAfter(array $unrun, array $holding, CoveredLine $line): array
    {
        foreach ($holding as $at) {
            if (array_key_exists($at, $unrun) && $this->runsOn($unrun[$at], $line)) {
                unset($unrun[$at]);
            }
        }

        return $unrun;
    }

    /** Whether one of a hold's tests runs a line, of a file it holds. */
    private function runsOn(Addition $addition, CoveredLine $line): bool
    {
        foreach ($line as $test) {
            if ($addition->adds(TestId::of($test), $line->file())) {
                return true;
            }
        }

        return false;
    }
}
