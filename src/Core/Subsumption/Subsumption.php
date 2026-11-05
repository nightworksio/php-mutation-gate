<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Subsumption;

use function array_all;
use function array_filter;

use const ARRAY_FILTER_USE_KEY;

use function array_intersect_key;
use function array_key_exists;
use function array_values;
use function count;

use IteratorAggregate;

use function ksort;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use Traversable;

/**
 * Which mutant of each file subsumes which, as the newest run with a full
 * kill matrix learned it (ADR-0079). One killed mutant subsumes another of
 * its file, also killed, where every test that killed the first killed the
 * second too, and the first has fewer killers, or as many and the lower id.
 * Each subsumed mutant keeps one mutant that subsumes it: the one with the
 * fewest killers, then the lowest id.
 *
 * @implements IteratorAggregate<string, list<array{MutantId, MutantId}>>
 */
final readonly class Subsumption implements IteratorAggregate
{
    /**
     * @var array<string, array<string, array{MutantId, MutantId}>> by file, in the order of the paths,
     *                                                               then by the subsumed mutant's id's
     *                                                               key: that mutant and the one that
     *                                                               subsumes it
     */
    private array $files;

    /** @param array<string, array<string, array{MutantId, MutantId}>> $files by file, then by the subsumed */
    private function __construct(array $files)
    {
        ksort($files);
        $this->files = $files;
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * What these mutants of one run, each judged with every test that killed
     * it, say of which subsumes which.
     */
    public static function learned(Mutant ...$mutants): self
    {
        $killed = [];

        foreach ($mutants as $mutant) {
            $killers = self::killersOf($mutant);
            $file = $mutant->location()->file()->value();
            $killed[$file] ??= [];
            $killed[$file] += $killers === [] ? [] : [$mutant->id()->key() => [$mutant->id(), $killers]];
        }

        $files = [];

        foreach ($killed as $file => $ofFile) {
            $subsumed = self::subsumedIn($ofFile);
            $files += $subsumed === [] ? [] : [$file => $subsumed];
        }

        return new self($files);
    }

    /** This, with a mutant of a file and the one that subsumes it added to what it holds of that file. */
    public function with(Path $file, MutantId $subsumed, MutantId $by): self
    {
        $files = $this->files;
        $files[$file->value()][$subsumed->key()] = [$subsumed, $by];
        return new self($files);
    }

    /**
     * This, after a run with a full kill matrix: what it learned of each file
     * it judged every mutant of in place of what this held, a unit any of
     * whose mutants it left unjudged teaching nothing.
     */
    public function afterRun(UnitResults $fresh): self
    {
        $judged = Paths::none();
        $mutants = [];

        foreach ($fresh as $result) {
            $ofUnit = [...$result->mutants()];
            $whole = array_all($ofUnit, static fn(Mutant $one): bool => $one->status() !== MutantStatus::Unjudged);
            $mutants = $whole ? [...$mutants, ...$ofUnit] : $mutants;
        }

        foreach ($mutants as $mutant) {
            $judged = $judged->with($mutant->location()->file());
        }

        return $this->after(self::learned(...$mutants), $judged);
    }

    /** This, read before another ledger's: each file as this holds it, and the other's that this holds nothing of. */
    public function and(self $other): self
    {
        $files = $this->files + $other->files;
        return new self($files);
    }

    /** Whether it holds what a run learned of this file. */
    public function knows(Path $file): bool
    {
        return array_key_exists($file->value(), $this->files);
    }

    /** The mutant that subsumes a mutant of this file; none where none does. */
    public function subsumerOf(Path $file, MutantId $mutant): MutantId|NotGiven
    {
        $subsumed = array_key_exists($file->value(), $this->files) ? $this->files[$file->value()] : [];

        return array_key_exists($mutant->key(), $subsumed) ? $subsumed[$mutant->key()][1] : NotGiven::value();
    }

    /** @return Traversable<string, list<array{MutantId, MutantId}>> each file's subsumed mutants, with each subsumer */
    public function getIterator(): Traversable
    {
        foreach ($this->files as $file => $subsumed) {
            yield $file => array_values($subsumed);
        }
    }

    /**
     * This, after a newer run judged these files: what that run learned of
     * each of them in place of what this held, which is nothing for a file
     * it learned no mutant subsumed in.
     */
    private function after(self $newer, Paths $judged): self
    {
        $kept = array_filter(
            $this->files,
            static fn(string $file): bool => ! $judged->has(Path::of($file)),
            ARRAY_FILTER_USE_KEY,
        );
        $files = [...$kept, ...$newer->files];
        return new self($files);
    }

    /**
     * The tests that killed a mutant, as keys; none where it was not killed.
     *
     * @return array<string, true>
     */
    private static function killersOf(Mutant $mutant): array
    {
        $killers = [];

        foreach ($mutant->status() === MutantStatus::Killed ? $mutant->killers() : [] as $test) {
            $killers[$test->value()] = true;
        }

        return $killers;
    }

    /**
     * Each killed mutant of a file another subsumes, with the one that
     * subsumes it that has the fewest killers, then the lowest id.
     *
     * @param  array<string, array{MutantId, array<string, true>}> $killed each mutant and its killers, by its id's key
     * @return array<string, array{MutantId, MutantId}>
     */
    private static function subsumedIn(array $killed): array
    {
        $subsumed = [];

        foreach ($killed as $key => [$mutant, $killers]) {
            $best = self::subsumerIn($killed, $mutant, $killers);
            $subsumed += $best instanceof MutantId ? [$key => [$mutant, $best]] : [];
        }

        ksort($subsumed);

        return $subsumed;
    }

    /**
     * The killed mutant that subsumes this one that has the fewest killers,
     * then the lowest id; none where none does.
     *
     * @param array<string, array{MutantId, array<string, true>}> $killed  each mutant and its killers, by its id's key
     * @param array<string, true>                                 $killers the mutant's own killers
     */
    private static function subsumerIn(array $killed, MutantId $mutant, array $killers): MutantId|NotGiven
    {
        $best = NotGiven::value();

        foreach ($killed as [$other, $otherKillers]) {
            $closer = $best instanceof NotGiven || self::before($other, $otherKillers, ...$best);
            $subsumes = $closer && self::subsumes($other, $otherKillers, $mutant, $killers);
            $best = $subsumes ? [$other, $otherKillers] : $best;
        }

        return $best instanceof NotGiven ? $best : $best[0];
    }

    /**
     * Whether a killed mutant subsumes another: every test that killed it
     * killed the other, and it comes before the other.
     *
     * @param array<string, true> $byKillers
     * @param array<string, true> $ofKillers
     */
    private static function subsumes(MutantId $by, array $byKillers, MutantId $of, array $ofKillers): bool
    {
        return count(array_intersect_key($byKillers, $ofKillers)) === count($byKillers)
            && self::before($by, $byKillers, $of, $ofKillers);
    }

    /**
     * Whether a mutant comes before another: it has fewer killers, or as many and the lower id.
     *
     * @param array<string, true> $firstKillers
     * @param array<string, true> $secondKillers
     */
    private static function before(MutantId $first, array $firstKillers, MutantId $second, array $secondKillers): bool
    {
        return count($firstKillers) < count($secondKillers)
            || count($firstKillers) === count($secondKillers) && $first->value() < $second->value();
    }
}
