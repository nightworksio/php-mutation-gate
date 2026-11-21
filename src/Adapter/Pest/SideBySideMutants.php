<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_fill;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_search;
use function array_values;

use Closure;

use function count;

use Fidry\CpuCoreCounter\CpuCoreCounter;

use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function getenv;
use function getmypid;
use function is_file;
use function is_int;
use function is_string;
use function min;

use NightWorksIO\MutationGate\Adapter\Pest\Split\OwnEnd;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function pcntl_fork;
use function pcntl_waitpid;

use Pest\Mutate\Mutation;

use function rename;

use const SIGKILL;

use function sprintf;
use function strval;

use Symfony\Component\Finder\SplFileInfo;

use function sys_get_temp_dir;
use function unlink;
use function usort;

/**
 * Every file's mutants a Pest run makes, made side by side before the run
 * takes any (ADR-0166): the files are cut by size into a share for each
 * core, each share is made by a child forked from the run, which leaves
 * what it made, and the schema of each file (see Schemata), in a file of
 * its own and ends. The run takes each file's mutants as a child made
 * them, in the order Pest makes them, and makes those of a file no child
 * left as before: where the run cannot fork, the machine has one core, or
 * a child failed.
 *
 * @internal
 */
final readonly class SideBySideMutants
{
    private const string LEFT = '%s/mutation-gate-made-%d-%d.json';

    private const string PART = '%s.part';

    private const string MADE = 'made';

    private const string SCHEMATA = 'schemata';

    /**
     * @param array<string, list<Mutation>>                         $made   each file's mutants a child made, by path
     * @param Closure(SplFileInfo, list<int>): array<int, Mutation> $making a file's mutants, as Pest makes them
     * @param array<string, list<int>|false>                       $lines  each file's lines to mutate, false where
     *                                                                     none, by path
     */
    private function __construct(
        private array $made,
        private Closure $making,
        private array $lines,
        private bool $cut,
    ) {
    }

    /**
     * Every file's mutants, made side by side where the run asks for it.
     *
     * @param iterable<SplFileInfo>                                  $files
     * @param Closure(SplFileInfo): (list<int>|false)                $lines  a file's lines to mutate, false where none
     * @param Closure(SplFileInfo, list<int>): array<int, Mutation> $making a file's mutants, as Pest makes them
     * @param bool                                                  $cut    whether a part of a cut run's tests
     *                                                                     picks the lines, so a file's whole
     *                                                                     list places its mutants (ADR-0004)
     */
    public static function made(iterable $files, Closure $lines, Closure $making, bool $cut = false): self
    {
        $jobs = [];
        $mutating = [];

        foreach ($files as $file) {
            $mutated = $lines($file);
            $mutating[strval($file->getRealPath())] = $mutated;
            $jobs = $mutated === false ? $jobs : [...$jobs, [$file, $mutated]];
        }

        $cores = function_exists('pcntl_fork') && getenv(GateVariable::MakingSideBySide->value) === '1'
            ? min(count($jobs), new CpuCoreCounter()->getCountWithFallback(1))
            : 1;

        return new self(
            $cores < 2 ? [] : self::left($jobs, self::shares($jobs, $cores), $making, $cut),
            $making,
            $mutating,
            $cut,
        );
    }

    /**
     * A file's mutants: as a child made them, or made here of the lines the
     * run asked for, these where it asked for none; none of a file it asked
     * for no lines of.
     *
     * @param  list<int>      $lines
     * @return list<Mutation>
     */
    public function of(SplFileInfo $file, array $lines): array
    {
        $path = strval($file->getRealPath());
        $mutated = array_key_exists($path, $this->lines) ? $this->lines[$path] : $lines;

        if (array_key_exists($path, $this->made)) {
            return $this->made[$path];
        }

        return $mutated === false ? [] : self::placed($file, $mutated, $this->making, $this->cut);
    }

    /**
     * A file's mutants made of these lines, each placed among the mutants of
     * its whole file that make its change: those of every line, made apart
     * with no schema touched, where a part of a cut run picked some lines.
     *
     * @param  list<int>                                            $lines
     * @param  Closure(SplFileInfo, list<int>): array<int, Mutation> $making
     * @return list<Mutation>
     */
    private static function placed(SplFileInfo $file, array $lines, Closure $making, bool $cut): array
    {
        $made = array_values($making($file, $lines));
        $whole = $cut && $lines !== [] ? Schemata::off(static fn(): array => $making($file, [])) : $made;
        ChangePlaces::among($made, $whole);

        return $made;
    }

    /**
     * Each job's place in a share, the largest files first, each into the share holding the fewest bytes yet.
     *
     * @param  list<array{SplFileInfo, list<int>}> $jobs
     * @param  int<1, max>                         $count
     * @return list<list<int>>
     */
    private static function shares(array $jobs, int $count): array
    {
        $sizes = array_map(
            static fn(array $job): int => $job[0]->getSize() === false ? 0 : $job[0]->getSize(),
            $jobs,
        );
        $places = array_keys($sizes);
        usort($places, static fn(int $one, int $other): int => $sizes[$other] <=> $sizes[$one]);
        $shares = array_fill(0, $count, []);
        $bytes = array_fill(0, $count, 0);

        foreach ($places as $place) {
            $least = (int) array_search(min($bytes), $bytes, strict: true);
            $shares[$least][] = $place;
            $bytes[$least] += $sizes[$place];
        }

        return $shares;
    }

    /**
     * What each child left of the files of its share, read once every child ended.
     *
     * @param  list<array{SplFileInfo, list<int>}>                  $jobs
     * @param  list<list<int>>                                      $shares
     * @param  Closure(SplFileInfo, list<int>): array<int, Mutation> $making
     * @return array<string, list<Mutation>>
     */
    private static function left(array $jobs, array $shares, Closure $making, bool $cut): array
    {
        $children = [];

        foreach ($shares as $number => $share) {
            $left = sprintf(self::LEFT, sys_get_temp_dir(), getmypid(), $number);
            $child = pcntl_fork();
            $child === 0 ? self::child($jobs, $share, $making, $cut, $left) : null;
            $children += $child > 0 ? [$left => $child] : [];
        }

        $made = [];

        foreach ($children as $left => $child) {
            pcntl_waitpid($child, $status);
            $made += self::read($left, $jobs);
        }

        return $made;
    }

    /**
     * Makes the mutants of a share's files, leaves them in a file, and ends
     * the child as a signal ends it, so nothing the run set to happen at its
     * end happens twice.
     *
     * @param list<array{SplFileInfo, list<int>}>                  $jobs
     * @param list<int>                                            $share
     * @param Closure(SplFileInfo, list<int>): array<int, Mutation> $making
     */
    private static function child(array $jobs, array $share, Closure $making, bool $cut, string $left): never
    {
        try {
            self::leave($jobs, $share, $making, $cut, $left);
        } finally {
            OwnEnd::by(SIGKILL);
        }
    }

    /**
     * Leaves the mutants of a share's files, and their schemata, in a file, whole once it is there.
     *
     * @param list<array{SplFileInfo, list<int>}>                  $jobs
     * @param list<int>                                            $share
     * @param Closure(SplFileInfo, list<int>): array<int, Mutation> $making
     */
    private static function leave(array $jobs, array $share, Closure $making, bool $cut, string $left): void
    {
        $made = [];
        $files = [];

        foreach ($share as $place) {
            [$file, $lines] = $jobs[$place];
            $files[] = strval($file->getRealPath());
            $made[] = Member::of(
                sprintf('%d', $place),
                Json::items(...array_map(self::written(...), self::placed($file, $lines, $making, $cut))),
            );
        }

        $part = sprintf(self::PART, $left);
        $text = Json::object(
            Member::of(self::MADE, Json::object(...$made)),
            Member::of(self::SCHEMATA, Schemata::keptOf($files)),
        )->printed();
        file_put_contents($part, $text);
        rename($part, $left);
    }

    /** A mutant as a child leaves it: its fields, then its place among its whole file's where the run placed it. */
    private static function written(Mutation $mutation): Json
    {
        return Json::items(
            Json::items(
                $mutation->id,
                $mutation->mutator,
                $mutation->startLine,
                $mutation->endLine,
                ByteText::of($mutation->diff),
                ByteText::of($mutation->modifiedSourcePath),
            ),
            ...self::placeOf($mutation),
        );
    }

    /** @return list<int> the mutant's place among its whole file's, where the run placed it */
    private static function placeOf(Mutation $mutation): array
    {
        $place = ChangePlaces::of($mutation);

        return is_int($place) ? [$place] : [];
    }

    /**
     * The mutants a child left of its share's files, by each file's real
     * path, its schemata taken on; none where it left nothing whole.
     *
     * @param  list<array{SplFileInfo, list<int>}> $jobs
     * @return array<string, list<Mutation>>
     */
    private static function read(string $left, array $jobs): array
    {
        $text = is_file($left) ? file_get_contents($left) : false;
        is_file($left) ? unlink($left) : null;

        try {
            $read = is_string($text) ? Node::decode($text) : null;
            $made = [];

            foreach ($read?->field(self::MADE)->entries() ?? [] as $place => $mutants) {
                $file = $jobs[(int) $place][0];
                $made[strval($file->getRealPath())] = array_map(
                    static fn(Node $mutant): Mutation => self::mutant($file, $mutant),
                    $mutants->items(),
                );
            }

            $read instanceof Node ? Schemata::taking($read->field(self::SCHEMATA)) : null;
        } catch (NotInShape) {
            return [];
        }

        return $made;
    }

    private static function mutant(SplFileInfo $file, Node $written): Mutation
    {
        $items = $written->items();
        [$fields] = $items;
        [$id, $mutator, $start, $end, $diff, $copy] = $fields->items();
        $place = array_key_exists(1, $items) ? $items[1] : null;
        $mutant = new Mutation(
            $file,
            $id->text(),
            $mutator->text(),
            $start->integer(),
            $end->integer(),
            ByteText::read($diff),
            ByteText::read($copy),
        );
        $place instanceof Node ? ChangePlaces::placing($mutant, $place->integer()) : null;

        return $mutant;
    }
}
