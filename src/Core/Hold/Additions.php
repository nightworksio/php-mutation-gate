<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use function array_any;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;

use function sprintf;

use Traversable;

/**
 * Every hold written only in the suites `tests.holding` lists (ADR-0005,
 * decision 9). A holding suite's test is kept in a coverage map only on the
 * lines it runs inside the paths it holds, where it judges as any covering
 * test does, and each hold must run a line of what it holds.
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
        foreach ($this->additions as $addition) {
            if (! $this->runs($addition, $map)) {
                return CannotJudge::because(sprintf(self::UNRUN, $addition->written(), $addition->path()->value()));
            }
        }

        return NotGiven::value();
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

    private function runs(Addition $addition, CoverageMap $map): bool
    {
        foreach ($map->lines() as $line) {
            foreach ($line as $test) {
                if ($addition->adds(TestId::of($test), $line->file())) {
                    return true;
                }
            }
        }

        return false;
    }
}
