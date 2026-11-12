<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function array_key_exists;
use function getenv;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Core\Config\Duplicates;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use Pest\Mutate\Mutation;

use function sprintf;

/**
 * The mutants Pest made that leave their file as one it made before them does
 * (ADR-0025), or that the negation rule makes one program with one made
 * before them (see NegationTwins, ADR-0137). Pest names each mutated copy by
 * a digest of its whole source, so two mutants of one file with the same copy
 * are one program. A rule's twin is recorded served as the copy of the mutant
 * that judges it. Under `mutants.duplicates: judge-each` none is a twin.
 * Where the gate records the run, the patched generation loop keeps each such
 * mutant out of Pest's run, and the plugin records it beside those Pest
 * planned, so the adapter judges it as the first: by its copy's run.
 *
 * @internal
 */
final class Twins
{
    /** @var array<string, array<string, true>> each copy a mutant left its file as, by its file, by the results file */
    private static array $made = [];

    /** @var array<string, list<PlannedMutant>> each mutant kept out, in the order Pest made it, by the results file */
    private static array $twins = [];

    /**
     * @var array<string, array<string, list<array{string, string}>>> each mutant the negation rule names, its
     *                                                               mutator and copy, by its file and lines, by
     *                                                               the results file
     */
    private static array $negations = [];

    /**
     * Whether the run the gate records in this results file holds a mutant
     * that leaves its file as this one does, made before it; this one is
     * then kept as its twin. Where the gate records nothing, none is.
     */
    public static function isTwin(Mutation $mutation, string $results): bool
    {
        if ($results === '' || getenv(GateVariable::Duplicates->value) === Duplicates::JudgeEach->value) {
            return false;
        }

        $copy = sprintf('%s %s', $mutation->file->getRealPath(), $mutation->modifiedSourcePath);

        if (array_key_exists($results, self::$made) && array_key_exists($copy, self::$made[$results])) {
            self::$twins[$results][] = Recorder::madeOf($mutation);

            return true;
        }

        self::$made[$results][$copy] = true;
        $first = self::negatedTwinOf($mutation, $results);

        if ($first === '') {
            return false;
        }

        self::$twins[$results][] = Recorder::madeOf($mutation)->servedAs(DiskPath::of($first));

        return true;
    }

    /**
     * Each mutant the run recorded in this results file kept out as a twin.
     *
     * @return list<PlannedMutant>
     */
    public static function of(string $results): array
    {
        return array_key_exists($results, self::$twins) ? self::$twins[$results] : [];
    }

    /**
     * The copy of the mutant made before this one that the negation rule makes one program with it (see
     * NegationTwins), which judges it; none where none does, this one then kept for a later one to pair with.
     */
    private static function negatedTwinOf(Mutation $mutation, string $results): string
    {
        if (! NegationTwins::names($mutation->mutator)) {
            return '';
        }

        $site = sprintf('%s:%d:%d', $mutation->file->getRealPath(), $mutation->startLine, $mutation->endLine);
        $made = self::$negations[$results][$site] ?? [];

        foreach ($made as [$mutator, $copy]) {
            if (NegationTwins::alike($mutation->mutator, $mutation->modifiedSourcePath, $mutator, $copy)) {
                return $copy;
            }
        }

        self::$negations[$results][$site][] = [$mutation->mutator, $mutation->modifiedSourcePath];

        return '';
    }
}
