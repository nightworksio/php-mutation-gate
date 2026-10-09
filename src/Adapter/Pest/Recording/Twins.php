<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function array_key_exists;

use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use Pest\Mutate\Mutation;

use function sprintf;

/**
 * The mutants Pest made that leave their file as one it made before them
 * does (ADR-0025, decision 13). Pest names each mutated copy by a digest of
 * its whole source, so two mutants of one file with the same copy are one
 * program. Where the gate records the run, the patched generation loop keeps
 * each such mutant out of Pest's run, and the plugin records it beside those
 * Pest planned, so the adapter judges it as the first: by its copy's run.
 */
final class Twins
{
    /** @var array<string, array<string, true>> each copy a mutant left its file as, by its file, by the results file */
    private static array $made = [];

    /** @var array<string, list<PlannedMutant>> each mutant kept out, in the order Pest made it, by the results file */
    private static array $twins = [];

    /**
     * Whether the run the gate records in this results file holds a mutant
     * that leaves its file as this one does, made before it; this one is
     * then kept as its twin. Where the gate records nothing, none is.
     */
    public static function isTwin(Mutation $mutation, string $results): bool
    {
        if ($results === '') {
            return false;
        }

        $copy = sprintf('%s %s', $mutation->file->getRealPath(), $mutation->modifiedSourcePath);

        if (! array_key_exists($results, self::$made) || ! array_key_exists($copy, self::$made[$results])) {
            self::$made[$results][$copy] = true;

            return false;
        }

        self::$twins[$results][] = Recorder::madeOf($mutation);

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
}
