<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Heat;

use function array_filter;
use function array_key_exists;
use function array_slice;

use ArrayIterator;
use Closure;

use function count;

use Countable;

use function in_array;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Comment\Cap;
use NightWorksIO\MutationGate\Core\Config\CommentShows;
use NightWorksIO\MutationGate\Core\Config\HeatmapRows;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use Traversable;

use function usort;

/**
 * The score of every file a verdict judged, lowest first, each with those
 * of the functions that hold its mutants and its change against the base
 * (ADR-0100): what the HTML report shows whole, the dashboard shows the
 * lowest of, and the pull request comment shows the files the change
 * reaches of, those that fell most and those below their tree's floor.
 *
 * @implements IteratorAggregate<int, FileHeat>
 */
final readonly class Heatmap implements Countable, IteratorAggregate
{
    /**
     * @param list<FileHeat> $files lowest score first
     * @param HeatmapRows    $rows  how many files {@see lowest()} lists
     */
    private function __construct(private array $files, private HeatmapRows $rows)
    {
    }

    public static function none(): self
    {
        return new self([], self::unlisted());
    }

    /**
     * Every file of these verdicts, each mutant with the narrowest of its file's functions that holds its line.
     *
     * @param Closure(Path): FunctionSpans $spans the functions of a file
     */
    public static function of(TreeVerdicts $verdicts, Closure $spans): self
    {
        $files = [];

        foreach ($verdicts as $tree) {
            $files = [...$files, ...self::filesOf($tree, $spans)];
        }

        usort($files, static fn(FileHeat $one, FileHeat $other): int => Heats::compare($one->heat(), $other->heat()));

        return new self($files, self::unlisted());
    }

    /** This, listing as many of its lowest-scoring files as `heatmap.files` says. */
    public function listing(HeatmapRows $rows): self
    {
        return new self($this->files, $rows);
    }

    /** This, each file compared with the base's file at its path, where the base has one. */
    public function comparedWith(self $base): self
    {
        $before = [];

        foreach ($base->files as $file) {
            $before[$file->heat()->name()] = $file;
        }

        $files = [];

        foreach ($this->files as $file) {
            $files[] = array_key_exists($file->heat()->name(), $before)
                ? $file->comparedWith($before[$file->heat()->name()])
                : $file;
        }

        return new self($files, $this->rows);
    }

    /**
     * The lowest-scoring files that have a score, as many as it lists.
     *
     * @return Listed<FileHeat>
     */
    public function lowest(): Listed
    {
        $scored = array_filter(
            $this->files,
            static fn(FileHeat $file): bool => $file->heat()->score() instanceof Score,
        );

        return Listed::of(...array_slice($scored, 0, $this->rows->files()));
    }

    /**
     * The files the change reaches whose score fell most since the base, as
     * many as the comment shows, then any other it reaches below its tree's
     * floor; none where the comment shows none.
     *
     * @return Listed<FileHeat>
     */
    public function fallen(CommentShows $comment): Listed
    {
        $most = $comment->most(Cap::Heatmap);

        if ($most === 0) {
            return Listed::of();
        }

        $touched = array_filter($this->files, static fn(FileHeat $file): bool => $file->isTouched());
        $dropped = array_filter($touched, static fn(FileHeat $file): bool => $file->heat()->drop() > 0);
        usort($dropped, $this->fellMore(...));
        $fallen = array_slice($dropped, 0, $most);
        $below = array_filter(
            $touched,
            static fn(FileHeat $file): bool => $file->isBelowFloor() && ! in_array($file, $fallen, strict: true),
        );

        return Listed::of(...$fallen, ...$below);
    }

    public function count(): int
    {
        return count($this->files);
    }

    /** @return Traversable<int, FileHeat> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->files);
    }

    private static function unlisted(): HeatmapRows
    {
        return HeatmapRows::of(files: 0);
    }

    private function fellMore(FileHeat $one, FileHeat $other): int
    {
        return $other->heat()->drop() <=> $one->heat()->drop();
    }

    /**
     * @param  Closure(Path): FunctionSpans $spans
     * @return list<FileHeat>
     */
    private static function filesOf(TreeVerdict $tree, Closure $spans): array
    {
        $files = [];
        $byFile = Grouped::by(
            $tree->mutants(),
            static fn(JudgedMutant|JudgedKill $mutant): string => $mutant->mutant()->location()->file()->value(),
        );

        foreach ($byFile as $path => $mutants) {
            $functions = $spans(Path::of($path));
            $heats = [];
            $byFunction = Grouped::by(
                $mutants,
                static fn(JudgedMutant|JudgedKill $mutant): string|Nameless => $functions->at(
                    $mutant->mutant()->location()->start(),
                ),
            );

            foreach ($byFunction as $name => $held) {
                $heats[] = Heat::of($name, $held, $tree->uncovered());
            }

            $files[] = FileHeat::of(
                Heat::of($path, $mutants, $tree->uncovered()),
                Listed::of(...$heats),
                $tree->floor(),
                touched: count($mutants->changed()) > 0,
            );
        }

        return $files;
    }
}
