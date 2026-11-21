<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_shift;
use function array_values;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\NotGiven;
use Pest\Mutate\Mutation;

use function sprintf;
use function strval;

use WeakMap;

/**
 * Each mutant's place among the mutants of its file that make its change, its
 * mutator and its diff, as a run that makes every mutant of the file numbers
 * them: the occurrence its gate id holds (ADR-0306), whatever lines a part of
 * a cut run makes and whichever mutants a run is told to make.
 *
 * @internal
 */
final class ChangePlaces
{
    /** @var WeakMap<Mutation, int>|null */
    private static ?WeakMap $places = null;

    /**
     * Places these mutants among the file's whole list, which holds each of
     * them, by Pest's id and lines, in the order Pest makes them.
     *
     * @param array<int, Mutation> $made
     * @param array<int, Mutation> $whole
     */
    public static function among(array $made, array $whole): void
    {
        $free = [];
        $places = self::counted($whole);

        foreach (array_values($whole) as $at => $mutant) {
            $free[self::spotOf($mutant)][] = $places[$at];
        }

        foreach (array_values($made) as $mutant) {
            $spot = self::spotOf($mutant);
            $place = array_key_exists($spot, $free) ? array_shift($free[$spot]) : null;
            $place === null ? null : self::placing($mutant, $place);
        }
    }

    /**
     * Each of a whole file's mutants' place among those before it that make
     * its change, in the order Pest makes them.
     *
     * @param  array<int, Mutation> $whole
     * @return list<int>
     */
    public static function counted(array $whole): array
    {
        $counted = [];
        $places = [];

        foreach (array_values($whole) as $mutant) {
            $key = self::keyOf($mutant);
            $place = array_key_exists($key, $counted) ? $counted[$key] : 0;
            $counted[$key] = $place + 1;
            $places[] = $place;
        }

        return $places;
    }

    /** Places a mutant at the occurrence its gate id holds. */
    public static function placing(Mutation $mutation, int $place): void
    {
        self::$places ??= new WeakMap();
        self::$places[$mutation] = $place;
    }

    /** A mutant's place, where the run placed it; none where it did not. */
    public static function of(Mutation $mutation): int|NotGiven
    {
        $places = self::$places;

        return $places instanceof WeakMap && $places->offsetExists($mutation) ? $places[$mutation] : NotGiven::value();
    }

    private static function keyOf(Mutation $mutant): string
    {
        $path = Path::of(strval($mutant->file->getRealPath()));

        return MutantId::firstOf($path, $mutant->mutator, Diff::fromPest($mutant->diff))->value();
    }

    private static function spotOf(Mutation $mutant): string
    {
        return sprintf('%s:%d:%d', $mutant->id, $mutant->startLine, $mutant->endLine);
    }
}
